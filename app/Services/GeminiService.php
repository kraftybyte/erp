<?php

namespace App\Services;

use App\Settings\IntegrationSettings;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** @var callable|null handler cURL bersama: struk album dibaca berurutan, koneksinya dipakai ulang */
    private static $handler = null;

    /** Alasan permintaan terakhir gagal, untuk ditampilkan ke pengguna; null bila berhasil. */
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public function isConfigured(): bool
    {
        return filled(IntegrationSettings::geminiApiKey());
    }

    /**
     * Kirim permintaan kecil untuk memastikan key, model, dan saldo bisa dipakai.
     * Null bila berhasil; selain itu pesan yang bisa ditampilkan ke admin.
     */
    public function testConnection(?string $key = null, ?string $model = null): ?string
    {
        $key   = $key ?: IntegrationSettings::geminiApiKey();
        $model = $model ?: IntegrationSettings::geminiModel();

        if (blank($key)) {
            return 'API key Gemini belum diisi.';
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-goog-api-key' => $key])
                ->post(sprintf(self::ENDPOINT, $model), [
                    'contents' => [['parts' => [['text' => 'Balas dengan satu kata: OK']]]],
                ]);
        } catch (ConnectionException) {
            return 'Server Google tidak bisa dihubungi dari server ini.';
        }

        return $response->successful() ? null : self::explain($response, $model);
    }

    private static function explain(Response $response, string $model): string
    {
        $message = (string) $response->json('error.message');

        return match (true) {
            $response->status() === 402    => 'Saldo prabayar Gemini habis. Isi ulang di AI Studio → Billing.',
            $response->status() === 404    => "Model {$model} tidak ditemukan atau tidak tersedia untuk key ini.",
            $response->status() === 429    => 'Batas pemakaian Gemini terlampaui. Coba lagi nanti, atau naikkan tier di AI Studio.',
            str_contains($message, 'API key') => 'API key tidak valid.',
            default                        => 'Gemini menolak: ' . ($message ?: "HTTP {$response->status()}"),
        };
    }

    public function analyze(string $prompt): string
    {
        $response = $this->generate($prompt, timeout: 30);

        if (! $response) {
            return "AI request failed. Check logs.";
        }

        return $this->text($response) ?: 'No analysis generated';
    }

    /**
     * Minta jawaban JSON yang mengikuti $schema (JSON Schema).
     *
     * Mengembalikan null bila API gagal atau jawabannya bukan JSON, supaya
     * pemanggil bisa beralih ke jalur tanpa AI alih-alih ikut gagal.
     *
     * @param  array<int, array{mime: string, data: string}>  $files  gambar/PDF (isi mentah) yang ikut dibaca
     * @param  int  $timeout  detik; 0 = 30 dengan file, 15 tanpa file (mutasi rekening panjang butuh lebih)
     */
    public function extractJson(string $prompt, array $schema, array $files = [], int $timeout = 0): ?array
    {
        $response = $this->generate($prompt, [
            'temperature'        => 0,
            'responseMimeType'   => 'application/json',
            'responseJsonSchema' => $schema,
        ], timeout: $timeout ?: ($files ? 30 : 15), files: $files);

        if (! $response) {
            return null;
        }

        // Model kadang tetap membungkus JSON dengan ```json walau diminta JSON murni.
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($this->text($response)));
        $data = json_decode($json, true);

        if (! is_array($data)) {
            Log::warning('Gemini mengembalikan JSON tidak valid', ['text' => $json]);

            return null;
        }

        return $data;
    }

    private function generate(string $prompt, array $generationConfig = [], int $timeout = 30, array $files = []): ?Response
    {
        self::$lastError = null;

        if (! $this->isConfigured()) {
            Log::warning('API key Gemini belum diisi (Settings → Integrasi)');
            self::$lastError = 'API key Gemini belum diisi.';

            return null;
        }

        $parts = [['text' => $prompt]];
        foreach ($files as $file) {
            $parts[] = ['inlineData' => ['mimeType' => $file['mime'], 'data' => base64_encode($file['data'])]];
        }

        $payload = ['contents' => [['parts' => $parts]]];

        if ($generationConfig) {
            $payload['generationConfig'] = $generationConfig;
        }

        try {
            $response = Http::setHandler(self::$handler ??= Utils::chooseHandler())
                ->timeout($timeout)
                ->withHeaders(['X-goog-api-key' => IntegrationSettings::geminiApiKey()])
                ->post(sprintf(self::ENDPOINT, IntegrationSettings::geminiModel()), $payload);
        } catch (ConnectionException $e) {
            Log::error('Gemini API tidak bisa dihubungi', ['error' => $e->getMessage()]);
            self::$lastError = 'Server Google tidak bisa dihubungi dari server ini.';
            self::recordUsage(failed: true);

            return null;
        }

        if (! $response->successful()) {
            Log::error('Gemini API Error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            self::$lastError = self::explain($response, IntegrationSettings::geminiModel());
            self::recordUsage(failed: true);

            // Saldo habis, batas pemakaian, atau key salah: baca struk & pesan suara mati.
            if (in_array($response->status(), [400, 401, 402, 403, 404, 429], true)) {
                \App\Services\Telegram\AdminAlerts::once(
                    "gemini-{$response->status()}",
                    '⚠️ <b>Gemini gagal</b>: ' . e(self::$lastError) . "\nBaca struk otomatis, pesan suara, dan mutasi PDF/foto tidak jalan sampai ini dibereskan (Settings → Integrasi → Tes koneksi)."
                );
            }

            return null;
        }

        self::recordUsage(
            input: (int) $response->json('usageMetadata.promptTokenCount', 0),
            output: (int) $response->json('usageMetadata.candidatesTokenCount', 0) + (int) $response->json('usageMetadata.thoughtsTokenCount', 0),
        );

        return $response;
    }

    /**
     * Rekap harian di tabel ai_usages; diabaikan bila tabelnya belum ada.
     */
    private static function recordUsage(bool $failed = false, int $input = 0, int $output = 0): void
    {
        try {
            DB::table('ai_usages')->upsert(
                [['date' => today()->toDateString(), 'calls' => 1, 'failures' => (int) $failed, 'input_tokens' => $input, 'output_tokens' => $output, 'created_at' => now(), 'updated_at' => now()]],
                ['date'],
                [
                    'calls'         => DB::raw('calls + 1'),
                    'failures'      => DB::raw('failures + ' . (int) $failed),
                    'input_tokens'  => DB::raw('input_tokens + ' . $input),
                    'output_tokens' => DB::raw('output_tokens + ' . $output),
                    'updated_at'    => now(),
                ]
            );
        } catch (\Throwable) {
            // Pencatatan pemakaian tidak boleh menggagalkan permintaan AI.
        }
    }

    private function text(Response $response): string
    {
        return collect($response->json('candidates.0.content.parts', []))
            ->reject(fn ($part) => $part['thought'] ?? false)
            ->pluck('text')
            ->implode('');
    }
}
