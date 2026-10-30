<?php

namespace App\Filament\Resources\AdvanceResource\Pages;

use App\Filament\Resources\AdvanceResource;
use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Models\Realisasi;
use App\Models\RealisasiItem;
use App\Models\RealisasiFile;
use App\Services\RealisasiService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

class RealisasiAdvance extends Page
{
    use WithFileUploads;

    protected static string $resource = \App\Filament\Resources\AdvanceResource::class;

    public Advance $record;
    /** @var UploadedFile[] */
    public $uploadedFiles = [];
    public bool $showBatchModal = false;
    public bool $showReturnModal = false;
    public string $batchNotes = '';
    /** @var array<int, array<string, mixed>> */
    public $batchItems = [];
    /** @var UploadedFile|null */
    public $returnAttachment = null;
    /** @var array<int, array<string, mixed>> */
    public $batches = [];
    public float $totalRealisasi = 0;
    public float $remaining = 0;
    public float $progressPercentage = 0;
    public string $status = '';
    public string $statusMessage = '';

    protected static string $view = 'filament.pages.advance-realisasi';

    /*
    | Nota realisasi dan bukti pengembalian disimpan di disk public (bisa dibuka
    | lewat /storage), jadi hanya gambar atau PDF yang boleh masuk. Aturan mimes
    | memeriksa isi file, bukan nama atau ekstensi kiriman browser.
    */
    private const FILE_RULE = 'file|mimes:jpg,jpeg,png,webp,pdf|max:5120';

    public function mount(Advance $record): void
    {
        // Halaman ini hanya memeriksa izin melihat daftar advance; advance-nya
        // sendiri harus boleh dilihat (pemilik, atau Finance/Director/super_admin).
        abort_unless(AdvanceResource::canView($record), 403);

        $this->record = $record;
        $this->refreshStats();
    }

    /** Advance sudah dicairkan dan belum ditutup: realisasinya masih berjalan. */
    private const OPEN_STATUSES = [Advance::STATUS_DIBAYARKAN, Advance::STATUS_REALIZING, Advance::STATUS_REALIZED_USER, Advance::STATUS_READY_TO_CLOSE];

    private function isOpen(): bool
    {
        return in_array($this->record->refresh()->status, self::OPEN_STATUSES, true);
    }

    private function isFinance(): bool
    {
        return (bool) auth()->user()?->hasRole(['Finance', 'Director', 'super_admin']);
    }

    /**
     * Boleh menambah atau mengubah realisasi advance ini: pemiliknya atau
     * Finance/Director/super_admin, hanya setelah dicairkan dan sebelum
     * ditutup. Pemilik tidak bisa mengubah lagi setelah Siap Diselesaikan.
     */
    private function canChangeRealisasi(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        if ($this->isFinance()) {
            return true;
        }

        return auth()->id() === $this->record->user_id && $this->record->status !== Advance::STATUS_READY_TO_CLOSE;
    }

    /**
     * Tindakan Finance (pengembalian, persetujuan kelebihan, penyelesaian) hanya
     * untuk advance yang sudah dicairkan dan belum ditutup. Dulu tombolnya tetap
     * bekerja pada advance yang belum dibayar atau sudah Selesai.
     */
    private function refuseClosed(): bool
    {
        if ($this->isOpen()) {
            return false;
        }

        Notification::make()
            ->title('Advance ini tidak sedang direalisasi')
            ->body('Tindakan ini hanya untuk advance yang sudah dicairkan dan belum diselesaikan.')
            ->warning()
            ->send();

        return true;
    }

    public function getSubheading(): ?string
    {
        return collect([$this->record->keterangan_advance, $this->record->project ? ClientLabel::project($this->record->project) : null])
            ->filter()
            ->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        $over      = fn () => $this->status === 'over_run';
        $approved  = fn () => (bool) $this->record->overrun_approved_by;
        $financeOk = fn () => $this->isFinance() && in_array($this->record->status, self::OPEN_STATUSES, true);

        return [
            Action::make('view')
                ->label('Lihat Advance')
                ->color('gray')
                ->url(fn () => AdvanceResource::getUrl('view', ['record' => $this->record])),

            Action::make('approveOverrun')
                ->label('Setujui Kelebihan')
                ->color('primary')
                ->icon('heroicon-o-check-badge')
                ->visible(fn () => $financeOk() && $over() && ! $approved())
                ->requiresConfirmation()
                ->modalHeading('Setujui kelebihan realisasi?')
                ->modalDescription(fn () => 'Realisasi melebihi advance Rp ' . number_format(abs($this->remaining), 0, ',', '.') . '. Setelah disetujui, advance bisa diselesaikan.')
                ->modalSubmitActionLabel('Setujui')
                ->action(fn () => $this->approveOverrun()),

            Action::make('return')
                ->label('Catat Pengembalian Sisa')
                ->color('gray')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn () => $financeOk() && $this->status === 'under_run')
                ->action(fn () => $this->openReturnModal()),

            Action::make('finish')
                ->label('Selesaikan Advance')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->visible(fn () => $financeOk() && ($this->status === 'exact_match' || ($over() && $approved())))
                ->requiresConfirmation()
                ->modalHeading('Selesaikan advance ini?')
                ->modalDescription('Status menjadi Selesai dan realisasinya tidak bisa diubah lagi.')
                ->modalSubmitActionLabel('Selesaikan')
                ->action(fn () => $this->finishAdvance()),

            Action::make('addBatch')
                ->label('Tambah Batch')
                ->icon('heroicon-o-plus')
                ->visible(fn () => $this->canChangeRealisasi() && $this->record->canRealisasi())
                ->action(fn () => $this->openBatchModal()),
        ];
    }

    protected function getViewData(): array
    {
        $record = $this->record;

        return [
            'requester'  => $record->user?->fullName ?? $record->user?->name ?? '-',
            'paidAt'     => $record->paid_at ?? $record->updated_at,
            'canAdd'     => $this->canChangeRealisasi() && $record->canRealisasi(),
            'canDelete'  => $this->isFinance() && in_array($record->status, self::OPEN_STATUSES, true),
            'statusNote' => $this->statusNote(),
        ];
    }

    /**
     * Keadaan realisasi dan langkah berikutnya, dalam kalimat biasa. Null bila
     * belum ada batch: bagian kosong di bawahnya sudah menjelaskannya.
     *
     * @return array{color: string, title: string, body: string}|null
     */
    private function statusNote(): ?array
    {
        $rupiah = fn (float $amount) => 'Rp ' . number_format(abs($amount), 0, ',', '.');

        return match (true) {
            $this->record->status === Advance::STATUS_SELESAI => [
                'color' => 'success', 'title' => 'Advance selesai',
                'body'  => 'Realisasi sudah ditutup Finance dan tidak bisa diubah lagi.',
            ],
            ! in_array($this->record->status, self::OPEN_STATUSES, true) => [
                'color' => 'gray', 'title' => 'Advance belum dicairkan',
                'body'  => 'Realisasi bisa diisi setelah advance berstatus Dibayarkan.',
            ],
            $this->batches === [] => null,
            $this->status === 'exact_match' => [
                'color' => 'success', 'title' => 'Realisasi pas dengan advance',
                'body'  => 'Tidak ada sisa maupun kelebihan. Finance tinggal menyelesaikan advance ini.',
            ],
            $this->status === 'over_run' && $this->record->overrun_approved_by => [
                'color' => 'success', 'title' => 'Kelebihan ' . $rupiah($this->remaining) . ' sudah disetujui',
                'body'  => 'Finance tinggal menyelesaikan advance ini.',
            ],
            $this->status === 'over_run' => [
                'color' => 'warning', 'title' => 'Realisasi melebihi advance ' . $rupiah($this->remaining),
                'body'  => 'Menunggu persetujuan Finance. Batch baru ditahan sampai kelebihannya disetujui.',
            ],
            default => [
                'color' => 'info', 'title' => 'Sisa ' . $rupiah($this->remaining) . ' belum direalisasi',
                'body'  => 'Tambahkan batch untuk pengeluaran berikutnya, atau kembalikan sisanya ke Finance.',
            ],
        };
    }

    private function refuseChange(): void
    {
        Notification::make()
            ->title('Tidak bisa diubah')
            ->body('Realisasi advance ini hanya bisa diubah pemiliknya atau Finance, setelah dicairkan dan sebelum diselesaikan.')
            ->danger()
            ->icon('heroicon-o-shield-exclamation')
            ->send();
    }

    /**
     * Validasi file upload; bila ditolak, beri tahu lewat notifikasi (halaman
     * ini tidak menampilkan pesan error per field) lalu hentikan prosesnya.
     */
    private function validateFiles(array $rules): void
    {
        try {
            $this->validate($rules);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('File tidak diterima')
                ->body('Hanya gambar (JPG, PNG, WebP) atau PDF, maksimal 5 MB per file.')
                ->danger()
                ->send();

            throw $e;
        }
    }

    public function refreshStats(): void
    {
        $service = new RealisasiService();
        $this->totalRealisasi = $service->getTotalRealisasi($this->record);
        $this->remaining = (float) $this->record->paid - $this->totalRealisasi;
        $this->progressPercentage = $service->getProgressPercentage($this->record);
        $this->batches = $service->getBatchesWithDetails($this->record)
            ->map(fn ($b) => [
                'id' => $b->id,
                'batch_number' => $b->batch_number,
                'notes' => $b->notes,
                'created_at' => $b->created_at->toIso8601String(),
                'uploaded_by_name' => $b->uploadedBy->fullName ?? 'Unknown',
                'total_amount' => $b->getTotalAmount(),
                'items' => $b->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'description' => $i->description,
                    'quantity' => $i->quantity,
                    'price' => $i->price,
                    'subtotal' => $i->subtotal,
                    'files' => $i->files->map(fn ($f) => [
                        'id' => $f->id,
                        'original_name' => $f->original_name,
                        'filepath' => $f->filepath,
                        'filesize' => $f->filesize,
                        'mime_type' => $f->mime_type,
                        'is_image' => $f->isImage(),
                        'is_pdf' => $f->isPdf(),
                    ])->all(),
                ])->all(),
            ])->all();
        $evaluation = $service->evaluateStatus($this->record);
        $this->status = $evaluation['status'];
        $this->statusMessage = $evaluation['message'];
    }

    public function openBatchModal(): void
    {
        $this->reset(['batchNotes', 'uploadedFiles', 'batchItems']);
        $this->batchItems = [
            ['name' => '', 'description' => '', 'quantity' => 1, 'price' => 0]
        ];
        $this->showBatchModal = true;
        $this->dispatch('open-modal', id: 'realisasi-batch');
    }

    public function closeBatchModal(): void
    {
        $this->showBatchModal = false;
        $this->reset(['batchNotes', 'uploadedFiles', 'batchItems']);
        $this->dispatch('close-modal', id: 'realisasi-batch');
    }

    public function removeUploadedFile(int $index): void
    {
        unset($this->uploadedFiles[$index]);
        $this->uploadedFiles = array_values($this->uploadedFiles);
    }

    public function openReturnModal(): void
    {
        $this->returnAttachment = null;
        $this->showReturnModal = true;
        $this->dispatch('open-modal', id: 'realisasi-return');
    }

    public function closeReturnModal(): void
    {
        $this->showReturnModal = false;
        $this->returnAttachment = null;
        $this->dispatch('close-modal', id: 'realisasi-return');
    }

    public function addBatchItem(): void
    {
        $this->batchItems[] = ['name' => '', 'description' => '', 'quantity' => 1, 'price' => 0];
    }

    public function removeBatchItem(int $index): void
    {
        if (count($this->batchItems) > 1) {
            unset($this->batchItems[$index]);
            $this->batchItems = array_values($this->batchItems);
        }
    }

    public function saveBatch(): void
    {
        if (! $this->canChangeRealisasi()) {
            $this->refuseChange();

            return;
        }

        $validItems = array_filter($this->batchItems, fn($item) => !empty($item['name']) && $item['price'] > 0);

        if (empty($validItems)) {
            Notification::make()
                ->title('Belum ada item')
                ->body('Minimal harus ada 1 item dengan nama dan harga yang valid.')
                ->warning()
                ->icon('heroicon-o-exclamation-circle')
                ->send();
            return;
        }

        $this->validateFiles(['uploadedFiles.*' => self::FILE_RULE]);

        $stored = [];

        try {
            DB::transaction(function () use (&$stored) {
                $files = [];
                foreach ($this->uploadedFiles as $file) {
                    $path = $stored[] = $file->store('realisasi/' . $this->record->id, 'public');
                    $files[] = [
                        'original_name' => $file->getClientOriginalName(),
                        'filepath' => $path,
                        'filesize' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ];
                }

                $items = [];
                foreach ($this->batchItems as $item) {
                    if (!empty($item['name']) && $item['price'] > 0) {
                        $items[] = [
                            'name' => $item['name'],
                            'description' => $item['description'] ?? '',
                            'quantity' => $item['quantity'] ?? 1,
                            'price' => $item['price'],
                        ];
                    }
                }

                $service = new RealisasiService();
                $service->createBatch(
                    $this->record,
                    ['notes' => $this->batchNotes],
                    $items,
                    $files
                );
            });

            $this->closeBatchModal();
            $this->refreshStats();

            Notification::make()
                ->title('Batch tersimpan')
                ->body('Total realisasi dan sisa advance sudah diperbarui.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();
        } catch (\Exception $e) {
            Storage::disk('public')->delete($stored);

            Notification::make()
                ->title('Batch gagal disimpan')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }

    public function deleteBatch(int $batchId): void
    {
        // Hanya Finance/Director/super_admin, dan hanya selama advance belum ditutup.
        if (! $this->isFinance() || $this->refuseClosed()) {
            Notification::make()
                ->title('Tidak punya izin')
                ->body('Hanya Finance, Director, atau Super Admin yang dapat menghapus batch.')
                ->danger()
                ->icon('heroicon-o-shield-exclamation')
                ->send();
            return;
        }

        try {
            $batch = Realisasi::where('advance_id', $this->record->id)->findOrFail($batchId);
            $service = new RealisasiService();
            $service->deleteBatch($batch);
            $this->refreshStats();

            Notification::make()
                ->title('Batch dihapus')
                ->body('Total realisasi dan sisa advance sudah diperbarui.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Batch gagal dihapus')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }

    public function processReturn(): void
    {
        $service = new RealisasiService();

        // Hak akses dicek sebelum file apa pun disimpan.
        if (! $service->canUserProcessReturn()) {
            Notification::make()
                ->title('Tidak punya izin')
                ->body('Hanya Finance, Director, atau Super Admin yang dapat memproses return.')
                ->danger()
                ->icon('heroicon-o-shield-exclamation')
                ->send();
            return;
        }

        if ($this->refuseClosed()) {
            return;
        }

        if (!$this->returnAttachment) {
            Notification::make()
                ->title('Gagal')
                ->body('Unggah bukti transfer pengembaliannya dulu.')
                ->warning()
                ->icon('heroicon-o-exclamation-circle')
                ->send();
            return;
        }

        $this->validateFiles(['returnAttachment' => self::FILE_RULE]);

        $attachmentPath = null;

        try {
            // Nama acak dengan ekstensi dari isi file, bukan dari nama kiriman browser.
            $attachmentPath = $this->returnAttachment->store('returns/' . $this->record->id, 'public');

            $service->processReturn($this->record, $attachmentPath);

            Notification::make()
                ->title('Pengembalian tercatat')
                ->body('Rp ' . number_format($this->remaining, 0, ',', '.') . ' telah dicatat sebagai return dan saldo sudah dikembalikan.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();

            $this->closeReturnModal();
            $this->refreshStats();
        } catch (\Exception $e) {
            if ($attachmentPath) {
                Storage::disk('public')->delete($attachmentPath);
            }

            Notification::make()
                ->title('Pengembalian gagal dicatat')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }

    public function approveOverrun(): void
    {
        if ($this->refuseClosed()) {
            return;
        }

        // Disetujui dua kali dulu mengembalikan status ke "Sedang di-Realisasi User".
        if ($this->record->overrun_approved_by) {
            Notification::make()->title('Kelebihan ini sudah disetujui')->warning()->send();

            return;
        }

        try {
            $service = new RealisasiService();
            $service->approveOverrun($this->record);
            $this->record->refresh();
            $this->refreshStats();

            Notification::make()
                ->title('Kelebihan disetujui')
                ->body('Advance ini sekarang bisa diselesaikan.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Kelebihan gagal disetujui')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }

    public function finishAdvance(): void
    {
        if ($this->refuseClosed()) {
            return;
        }

        try {
            $service = new RealisasiService();
            $service->finishAdvance($this->record);
            $this->record->refresh();
            $this->refreshStats();

            Notification::make()
                ->title('Advance diselesaikan')
                ->body('Status berubah menjadi Selesai.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();

            $this->redirect('/admin/advances', navigate: true);
        } catch (\Exception $e) {
            Notification::make()
                ->title('Advance gagal diselesaikan')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }

    public function deleteFile(int $fileId): void
    {
        if (! $this->canChangeRealisasi()) {
            $this->refuseChange();

            return;
        }

        try {
            // Hanya file milik advance yang sedang dibuka.
            $file = RealisasiFile::whereHas('realizedItem.realizasi', fn ($q) => $q->where('advance_id', $this->record->id))->findOrFail($fileId);
            $service = new RealisasiService();
            $service->deleteFile($file);
            $this->refreshStats();

            Notification::make()
                ->title('File berhasil dihapus')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Gagal menghapus file')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function updateItem(int $itemId, string $name, ?string $description, int $quantity, float $price): void
    {
        if (! $this->canChangeRealisasi()) {
            $this->refuseChange();

            return;
        }

        try {
            // Hanya item milik advance yang sedang dibuka.
            $item = RealisasiItem::whereHas('realizasi', fn ($q) => $q->where('advance_id', $this->record->id))->findOrFail($itemId);
            $service = new RealisasiService();
            $service->updateItem($item, [
                'name' => $name,
                'description' => $description,
                'quantity' => $quantity,
                'price' => $price,
            ]);
            $this->refreshStats();

            Notification::make()
                ->title('Item diperbarui')
                ->body('Item realization telah diperbarui.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Item gagal diperbarui')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }
}