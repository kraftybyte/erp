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

    public function mount(Advance $record): void
    {
        $this->record = $record;
        $this->refreshStats();
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
                ->title('Gagal menyimpan')
                ->body('Minimal harus ada 1 item dengan nama dan harga.')
                ->danger()
                ->send();
            return;
        }

        try {
            DB::transaction(function () {
                $files = [];
                foreach ($this->uploadedFiles as $file) {
                    $path = $file->store('realisasi/' . $this->record->id, 'public');
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
                ->title('Batch berhasil ditambahkan')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Gagal menyimpan batch')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function deleteBatch(int $batchId): void
    {
        try {
            $batch = Realisasi::find($batchId);
            $service = new RealisasiService();
            $service->deleteBatch($batch);
            $this->refreshStats();

            Notification::make()
                ->title('Batch berhasil dihapus')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Gagal menghapus batch')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function processReturn(): void
    {
        if (!$this->returnAttachment) {
            Notification::make()
                ->title('Gagal')
                ->body('Bukti return harus diunggah.')
                ->danger()
                ->send();
            return;
        }

        try {
            $attachmentPath = $this->returnAttachment->storeAs(
                'returns/' . $this->record->id,
                uniqid() . '-' . now()->format('d-m-Y') . '.' . $this->returnAttachment->getClientOriginalExtension(),
                'public'
            );

            $service = new RealisasiService();
            $service->processReturn($this->record, $attachmentPath);

            Notification::make()
                ->title('Return berhasil')
                ->body('Rp ' . number_format($this->remaining, 0, ',', '.') . ' telah dicatat sebagai return.')
                ->success()
                ->send();

            $this->closeReturnModal();
            $this->refreshStats();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Gagal memproses return')
                ->body($e->getMessage())
                ->danger()
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
                ->title('Overrun disetujui')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Gagal menyetujui overrun')
                ->body($e->getMessage())
                ->danger()
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
                ->title('Advance diselesaikan')
                ->success()
                ->send();

            $this->redirect('/admin/advances', navigate: true);
        } catch (\Exception $e) {
            Notification::make()
                ->title('Gagal menyelesaikan advance')
                ->body($e->getMessage())
                ->danger()
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
                ->title('Item berhasil diupdate')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Gagal mengupdate item')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}