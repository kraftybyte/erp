<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\GeminiService;
use App\Services\Telegram\SubmissionParser;
use App\Settings\IntegrationSettings;
use Illuminate\Console\Command;

/**
 * Cek cepat cara bot membaca sebuah pesan, tanpa perlu Telegram.
 * Berguna untuk memastikan Gemini aktif (mis. setelah isi saldo).
 */
class TelegramParse extends Command
{
    protected $signature = 'telegram:parse {text : Pesan seperti yang diketik di Telegram}';

    protected $description = 'Tampilkan hasil bacaan bot Telegram untuk sebuah pesan';

    public function handle(SubmissionParser $parser, GeminiService $gemini): int
    {
        $projects = Project::latest('id')->limit(50)->get()
            ->mapWithKeys(fn (Project $p) => [$p->id => trim("{$p->nama_perusahaan} | {$p->nama_project}", ' |')])
            ->all();

        $this->line('Model: ' . ($gemini->isConfigured() ? IntegrationSettings::geminiModel() : '(API key Gemini kosong — memakai pembaca sederhana)'));

        $result = $parser->parse($this->argument('text'), $projects);

        $this->table(['Field', 'Hasil'], collect($result)->map(fn ($value, $field) => [$field, $value ?? '—'])->values()->all());

        $this->comment('Bila Gemini gagal, hasil di atas berasal dari pembaca sederhana. Lihat storage/logs/laravel.log untuk alasannya.');

        return self::SUCCESS;
    }
}
