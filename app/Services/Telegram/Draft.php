<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pengajuan yang sedang disusun lewat chat. Disimpan di cache, bukan database:
 * belum ada yang tercatat di ERP sampai user menekan Kirim.
 */
final class Draft
{
    private const TTL_HOURS = 2;

    public function __construct(
        // Ikut di setiap callback tombol, supaya tombol dari draft lama tidak
        // bisa mengirim draft yang sekarang.
        public string $id,
        public ?string $type = null,
        public ?int $amount = null,
        public ?string $description = null,
        public ?int $projectId = null,
        public ?string $category = null,
        public ?string $date = null,
        // Field yang sedang ditanyakan; balasan teks berikutnya mengisi field ini.
        public ?string $awaiting = null,
    ) {}

    public static function start(?string $type = null): self
    {
        return new self(id: Str::lower(Str::random(8)), type: $type, date: today()->toDateString());
    }

    public static function load(int|string $chatId): ?self
    {
        $data = Cache::get(self::key($chatId));

        return is_array($data) ? new self(...$data) : null;
    }

    public static function forget(int|string $chatId): void
    {
        Cache::forget(self::key($chatId));
    }

    public function save(int|string $chatId): void
    {
        Cache::put(self::key($chatId), get_object_vars($this), now()->addHours(self::TTL_HOURS));
    }

    /**
     * Isi field dari hasil SubmissionParser; nilai null tidak menimpa isian yang ada.
     */
    public function fill(array $parsed): self
    {
        $this->type        = $parsed['type'] ?? $this->type;
        $this->amount      = $parsed['amount'] ?? $this->amount;
        $this->description = $parsed['description'] ?? $this->description;
        $this->projectId   = $parsed['project_id'] ?? $this->projectId;
        $this->category    = $parsed['category'] ?? $this->category;
        $this->date        = $parsed['date'] ?? $this->date;

        return $this;
    }

    public function isComplete(): bool
    {
        return $this->type && $this->amount && $this->description && $this->projectId && $this->category && $this->date;
    }

    private static function key(int|string $chatId): string
    {
        return "telegram:draft:{$chatId}";
    }
}
