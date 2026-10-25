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
        // Project yang sama-sama cocok dengan pesan; ditawarkan lebih dulu
        // daripada daftar semua project.
        public array $projectCandidates = [],
        // Foto/berkas struk: hanya file_id Telegram. File-nya baru diunduh ke
        // ERP saat Kirim, supaya draft yang dibatalkan tidak meninggalkan file.
        // @var array{file_id: string, mime: string, ext: string, group: ?string}|null
        public ?array $attachment = null,
        // Realisasi: advance milik user yang dilaporkan pemakaiannya.
        public ?int $advanceId = null,
        public array $advanceCandidates = [],
        // Jenis sudah dipastikan user (ditulis sendiri atau dipilih lewat tombol),
        // jadi bot tidak lagi bertanya "realisasi atau expense?".
        public bool $typeConfirmed = false,
        // Dua struk atau lebih. Selama hanya satu struk, $attachment + $amount
        // yang dipakai; mulai struk kedua semuanya pindah ke sini, nominal per
        // struk, dan $amount menjadi total yang disebut user (untuk dicocokkan).
        // @var array<int, array{file_id: string, unique: ?string, mime: string, ext: string, group: ?string, message_id: ?int, amount: ?int, name: ?string, ai: bool}>
        public array $receipts = [],
        // Pesan ringkasan terakhir, supaya bisa diperbarui di tempat saat
        // struk dari album yang sama masih berdatangan.
        public ?int $panelMessageId = null,
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->type && ! $this->amount && ! $this->description && ! $this->projectId && ! $this->category;
    }

    public function isMulti(): bool
    {
        return count($this->receipts) > 1;
    }

    public function receiptCount(): int
    {
        return $this->receipts ? count($this->receipts) : ($this->attachment ? 1 : 0);
    }

    /**
     * Total yang akan dicatat: jumlah per struk, atau nominal tunggal.
     */
    public function total(): int
    {
        return $this->isMulti() ? (int) array_sum(array_column($this->receipts, 'amount')) : (int) $this->amount;
    }

    public function albumGroup(): ?string
    {
        return $this->attachment['group'] ?? $this->receipts[0]['group'] ?? null;
    }

    public function hasReceipt(?string $unique): bool
    {
        if (! $unique) {
            return false;
        }

        return ($this->attachment['unique'] ?? null) === $unique
            || in_array($unique, array_column($this->receipts, 'unique'), true);
    }

    public function nextReceiptWithoutAmount(): ?int
    {
        foreach ($this->receipts as $index => $receipt) {
            if (! $receipt['amount']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  bool  $sameAlbum  struk ini bagian dari album yang sama dengan struk yang sudah ada
     */
    public function addReceipt(array $file, bool $sameAlbum): void
    {
        $file += ['amount' => null, 'name' => null, 'ai' => false];

        if (! $this->attachment && ! $this->receipts) {
            $this->attachment = $file;

            return;
        }

        if ($this->attachment) {
            $first = $this->attachment + ['amount' => null, 'name' => null, 'ai' => false];

            // Foto susulan: nominal & keterangan yang sudah ditulis milik struk
            // pertama. Dalam satu album, nominal di caption adalah total album
            // dan tetap di $amount untuk dicocokkan dengan jumlah per struk.
            // Advance tidak dihitung per lampiran: nominalnya tetap nominal advance.
            if (! $sameAlbum && $this->type !== 'advance') {
                $first['amount'] = $this->amount;
                $first['name']   = $this->description;
                $this->amount    = null;
            }

            $this->receipts   = [$first];
            $this->attachment = null;
        }

        $this->receipts[] = $file;
    }

    public function removeReceipt(int $index): void
    {
        unset($this->receipts[$index]);
        $this->receipts = array_values($this->receipts);

        // Tinggal satu: kembali ke bentuk satu struk. Nominal struk itu sendiri
        // diutamakan; bila belum ada, nominal yang ditulis user di caption.
        if (count($this->receipts) === 1) {
            $last = $this->receipts[0];

            $this->attachment  = $last;
            $this->amount      = $last['amount'] ?? $this->amount;
            $this->description = $last['name'] ?? $this->description;
            $this->receipts    = [];
        }
    }

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

        $this->projectCandidates = $this->projectId ? [] : ($parsed['project_candidates'] ?? $this->projectCandidates);
        $this->typeConfirmed     = $this->typeConfirmed || ($parsed['type_explicit'] ?? false);

        return $this;
    }

    public function isComplete(): bool
    {
        if ($this->isMulti()) {
            $amountsDone = $this->nextReceiptWithoutAmount() === null;

            return match ($this->type) {
                // Nama item per struk opsional; kosong memakai keterangan umum.
                'realisasi' => $this->advanceId && $amountsDone,
                'expense'   => $amountsDone && $this->description && $this->projectId && $this->category && $this->date,
                // Lampiran advance hanya dokumen pendukung (penawaran, referensi),
                // bukan item biaya: cukup satu nominal advance.
                'advance'   => $this->amount && $this->description && $this->projectId && $this->category && $this->date,
                default     => false,
            };
        }

        // Realisasi mengikuti project & kategori advance-nya.
        if ($this->type === 'realisasi') {
            return $this->advanceId && $this->amount && $this->description;
        }

        return $this->type && $this->amount && $this->description && $this->projectId && $this->category && $this->date;
    }

    private static function key(int|string $chatId): string
    {
        return "telegram:draft:{$chatId}";
    }
}
