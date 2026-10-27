<?php

namespace App\Filament\Resources\AdvanceResource\Pages;

use App\Models\Advance;
use App\Models\Realisasi;
use App\Models\RealisasiItem;
use App\Models\RealisasiFile;
use App\Services\RealisasiService;
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
        $this->record = $record;
        $this->refreshStats();
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
    }

    public function closeBatchModal(): void
    {
        $this->showBatchModal = false;
        $this->reset(['batchNotes', 'uploadedFiles', 'batchItems']);
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
    }

    public function closeReturnModal(): void
    {
        $this->showReturnModal = false;
        $this->returnAttachment = null;
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
        $validItems = array_filter($this->batchItems, fn($item) => !empty($item['name']) && $item['price'] > 0);

        if (empty($validItems)) {
            Notification::make()
                ->title('⚠️ Item Kosong')
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
                ->title('✅ Batch Berhasil Ditambahkan')
                ->body('Batch realization baru telah ditambahkan.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();
        } catch (\Exception $e) {
            Storage::disk('public')->delete($stored);

            Notification::make()
                ->title('❌ Gagal Menyimpan Batch')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }

    public function deleteBatch(int $batchId): void
    {
        // Check permission - only Finance, Director, Super Admin can delete
        $user = auth()->user();
        if (!$user->hasRole(['super_admin', 'Finance', 'Director'])) {
            Notification::make()
                ->title('⚠️ Tidak Memiliki Izin')
                ->body('Hanya Finance, Director, atau Super Admin yang dapat menghapus batch.')
                ->danger()
                ->icon('heroicon-o-shield-exclamation')
                ->send();
            return;
        }

        try {
            $batch = Realisasi::find($batchId);
            $service = new RealisasiService();
            $service->deleteBatch($batch);
            $this->refreshStats();

            Notification::make()
                ->title('✅ Batch Berhasil Dihapus')
                ->body('Batch realization berhasil dihapus dan data sudah diperbarui.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('❌ Gagal Menghapus Batch')
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
                ->title('⚠️ Tidak Memiliki Izin')
                ->body('Hanya Finance, Director, atau Super Admin yang dapat memproses return.')
                ->danger()
                ->icon('heroicon-o-shield-exclamation')
                ->send();
            return;
        }

        if (!$this->returnAttachment) {
            Notification::make()
                ->title('Gagal')
                ->body('⚠️ Bukti return harus diunggah terlebih dahulu.')
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
                ->title('✅ Return Berhasil Dicatat')
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
                ->title('❌ Gagal Memproses Return')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }

    public function approveOverrun(): void
    {
        try {
            $service = new RealisasiService();
            $service->approveOverrun($this->record);
            $this->record->refresh();
            $this->refreshStats();

            Notification::make()
                ->title('✅ Overrun Disetujui')
                ->body('Kelebihan amount sudah disetujui. User dapat menambah batch lagi.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('❌ Gagal Menyetujui Overrun')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }

    public function finishAdvance(): void
    {
        try {
            $service = new RealisasiService();
            $service->finishAdvance($this->record);
            $this->record->refresh();
            $this->refreshStats();

            Notification::make()
                ->title('✅ Advance Diselesaikan')
                ->body('Advance berhasil diselesaikan. Status berubah menjadi Selesai.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();

            $this->redirect('/admin/advances', navigate: true);
        } catch (\Exception $e) {
            Notification::make()
                ->title('❌ Gagal Menyelesaikan Advance')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }

    public function deleteFile(int $fileId): void
    {
        try {
            $file = RealisasiFile::find($fileId);
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
        try {
            $item = RealisasiItem::find($itemId);
            $service = new RealisasiService();
            $service->updateItem($item, [
                'name' => $name,
                'description' => $description,
                'quantity' => $quantity,
                'price' => $price,
            ]);
            $this->refreshStats();

            Notification::make()
                ->title('✅ Item Berhasil Diupdate')
                ->body('Item realization telah diperbarui.')
                ->success()
                ->icon('heroicon-o-check-circle')
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('❌ Gagal Mengupdate Item')
                ->body($e->getMessage())
                ->danger()
                ->icon('heroicon-o-x-circle')
                ->send();
        }
    }
}