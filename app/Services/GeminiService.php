<?php

namespace App\Services;

use App\Settings\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

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

        $message = (string) $response->json('error.message');

        return match (true) {
            $response->successful()        => null,
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
     */
    public function extractJson(string $prompt, array $schema, array $files = []): ?array
    {
        $response = $this->generate($prompt, [
            'temperature'        => 0,
            'responseMimeType'   => 'application/json',
            'responseJsonSchema' => $schema,
        ], timeout: $files ? 30 : 15, files: $files);

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
        if (! $this->isConfigured()) {
            Log::warning('API key Gemini belum diisi (Settings → Integrasi)');

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
            $response = Http::timeout($timeout)
                ->withHeaders(['X-goog-api-key' => IntegrationSettings::geminiApiKey()])
                ->post(sprintf(self::ENDPOINT, IntegrationSettings::geminiModel()), $payload);
        } catch (ConnectionException $e) {
            Log::error('Gemini API tidak bisa dihubungi', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('Gemini API Error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return null;
        }

        return $response;
    }

    private function text(Response $response): string
    {
        return collect($response->json('candidates.0.content.parts', []))
            ->reject(fn ($part) => $part['thought'] ?? false)
            ->pluck('text')
            ->implode('');
    }
}
