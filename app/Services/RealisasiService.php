<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Realisasi;
use App\Models\RealisasiItem;
use App\Models\RealisasiFile;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RealisasiService - Business logic for Advance Realization
 *
 * Handles the complete workflow of advance realization:
 * - Creating batches with items and files
 * - Calculating totals and progress
 * - Processing returns and approvals
 * - Closing advances
 *
 * @since 2026-06-06
 * @author ERP Creative Team
 */
class RealisasiService
{
    /**
     * Validation error messages
     */
    public const ERROR_ADVANCE_NOT_PAYABLE = 'Advance belum bisa di-realisasi. Status harus "Dibayarkan".';
    public const ERROR_ADVANCE_COMPLETED = 'Advance sudah selesai dan tidak dapat dimodifikasi.';
    public const ERROR_TOTAL_EXCEEDS_PAID = 'Total batch melebihi jumlah advance yang dibayarkan.';
    public const ERROR_NO_ITEMS = 'Minimal harus ada 1 item dalam batch.';
    public const ERROR_INVALID_ITEMS = 'Data item tidak valid.';
    public const ERROR_NO_RETURN_NEEDED = 'Tidak ada sisa yang harus dikembalikan.';

    /**
     * Tolerance for exact match (Rp 100)
     */
    public const EXACT_MATCH_TOLERANCE = 100;

    /**
     * Create a new batch with items and files
     *
     * @param Advance $advance The advance to add realization to
     * @param array $data Batch data (notes, etc)
     * @param array $items Array of items with name, description, quantity, price
     * @param array $files Array of uploaded files
     * @return Realisasi The created batch
     * @throws \InvalidArgumentException When validation fails
     * @throws \Exception On other errors
     */
    public function createBatch(Advance $advance, array $data, array $items, array $files = []): Realisasi
    {
        // Validate advance can be realized
        $this->validateAdvanceCanRealisasi($advance);

        // Validate items
        $this->validateBatchItems($items);

        return DB::transaction(function () use ($advance, $data, $items, $files) {
            // Calculate current total and new batch total
            $currentTotal = $this->getTotalRealisasi($advance);
            $newBatchTotal = $this->calculateItemsTotal($items);
            $newTotal = $currentTotal + $newBatchTotal;

            // Validate total doesn't exceed paid amount
            $this->validateTotalNotExceedingPaid($advance, $newTotal);

            // Check if this is the first batch - if so, update status to 'Sudah di-Realisasi User'
            $isFirstBatch = $advance->realizasis()->count() === 0;

            // Create batch
            $batch = Realisasi::create([
                'advance_id' => $advance->id,
                'batch_number' => Realisasi::getNextBatchNumber($advance->id),
                'uploaded_by' => Auth::user()?->id ?? 1,
                'notes' => $data['notes'] ?? null,
            ]);

            // Create items
            $firstItem = null;
            foreach ($items as $itemData) {
                $item = RealisasiItem::create([
                    'realisasi_id' => $batch->id,
                    'name' => $itemData['name'],
                    'description' => $itemData['description'] ?? null,
                    'quantity' => $itemData['quantity'] ?? 1,
                    'price' => $itemData['price'],
                    // subtotal calculated automatically via model boot
                ]);

                if ($firstItem === null) {
                    $firstItem = $item;
                }
            }

            // Attach all batch files to the first item
            if ($firstItem && !empty($files)) {
                $this->attachFiles($firstItem, $files);
            }

            // Update advance status if this is the first batch
            if ($isFirstBatch) {
                $advance->update(['status' => Advance::STATUS_REALIZED_USER]);
            }

            return $batch;
        });
    }

    /**
     * Validate that advance can be realized
     *
     * @param Advance $advance
     * @throws \InvalidArgumentException
     */
    public function validateAdvanceCanRealisasi(Advance $advance): void
    {
        if ($advance->status === Advance::STATUS_SELESAI) {
            throw new \InvalidArgumentException(self::ERROR_ADVANCE_COMPLETED);
        }

        if (!$advance->canRealisasi()) {
            throw new \InvalidArgumentException(self::ERROR_ADVANCE_NOT_PAYABLE);
        }
    }

    /**
     * Validate batch items
     *
     * @param array $items
     * @throws \InvalidArgumentException
     */
    public function validateBatchItems(array $items): void
    {
        if (empty($items)) {
            throw new \InvalidArgumentException(self::ERROR_NO_ITEMS);
        }

        foreach ($items as $index => $item) {
            if (empty($item['name'])) {
                throw new \InvalidArgumentException(
                    "Item " . ($index + 1) . ": Nama item tidak boleh kosong."
                );
            }

            if (!isset($item['price']) || !is_numeric($item['price']) || $item['price'] < 0) {
                throw new \InvalidArgumentException(
                    "Item " . ($index + 1) . ": Harga tidak valid."
                );
            }

            $quantity = $item['quantity'] ?? 1;
            if (!is_numeric($quantity) || $quantity < 1) {
                throw new \InvalidArgumentException(
                    "Item " . ($index + 1) . ": Jumlah (quantity) tidak valid."
                );
            }
        }
    }

    /**
     * Calculate total from items array
     *
     * @param array $items
     * @return float
     */
    public function calculateItemsTotal(array $items): float
    {
        return collect($items)->sum(function ($item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            return $quantity * $price;
        });
    }

    /**
     * Validate that total doesn't exceed paid amount
     *
     * @param Advance $advance
     * @param float $newTotal
     * @throws \InvalidArgumentException
     */
    public function validateTotalNotExceedingPaid(Advance $advance, float $newTotal): void
    {
        if ($newTotal > (float) $advance->paid) {
            $excess = $newTotal - (float) $advance->paid;
            throw new \InvalidArgumentException(
                self::ERROR_TOTAL_EXCEEDS_PAID . " Kelebihan Rp " . number_format($excess, 0, ',', '.')
            );
        }
    }

    /**
     * Attach files to an item
     *
     * @param RealisasiItem $item
     * @param array $uploadedFiles
     */
    public function attachFiles(RealisasiItem $item, array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $fileData) {
            RealisasiFile::create([
                'realisasi_item_id' => $item->id,
                'original_name' => $fileData['original_name'],
                'filepath' => $fileData['filepath'],
                'filesize' => $fileData['filesize'] ?? 0,
                'mime_type' => $fileData['mime_type'] ?? null,
            ]);
        }
    }

    /**
     * Calculate total realized for an advance
     *
     * @param Advance $advance
     * @return float
     */
    public function getTotalRealisasi(Advance $advance): float
    {
        $batchIds = Realisasi::where('advance_id', $advance->id)->pluck('id');
        return (float) RealisasiItem::whereIn('realisasi_id', $batchIds)->sum('subtotal');
    }

    /**
     * Get remaining amount for an advance
     *
     * @param Advance $advance
     * @return float
     */
    public function getRemainingAmount(Advance $advance): float
    {
        $totalRealisasi = $this->getTotalRealisasi($advance);
        return (float) $advance->paid - $totalRealisasi;
    }

    /**
     * Get progress percentage
     *
     * @param Advance $advance
     * @return float
     */
    public function getProgressPercentage(Advance $advance): float
    {
        if ((float) $advance->paid <= 0) {
            return 0;
        }
        $totalRealisasi = $this->getTotalRealisasi($advance);
        return round(($totalRealisasi / (float) $advance->paid) * 100, 1);
    }

    /**
     * Determine and update advance status based on realization
     *
     * @param Advance $advance
     * @return array
     */
    public function evaluateStatus(Advance $advance): array
    {
        $totalRealisasi = $this->getTotalRealisasi($advance);
        $advanceAmount = (float) $advance->paid;
        $remaining = $advanceAmount - $totalRealisasi;

        $result = [
            'total_realisasi' => $totalRealisasi,
            'remaining' => $remaining,
            'overrun' => 0,
            'status' => 'unknown',
            'message' => '',
            'actions' => [],
        ];

        if (abs($remaining) < self::EXACT_MATCH_TOLERANCE) {
            // Exact match
            $result['status'] = 'exact_match';
            $result['message'] = 'Realisasi match dengan advance. Siap untuk diselesaikan.';
            $result['actions'] = ['finish'];
        } elseif ($remaining > 0) {
            // Under-run
            $result['status'] = 'under_run';
            $result['message'] = "Sisa Rp " . number_format($remaining, 0, ',', '.') . " harus dikembalikan oleh user.";
            $result['actions'] = ['return'];
        } else {
            // Over-run
            $result['overrun'] = abs($remaining);
            $result['status'] = 'over_run';
            $result['message'] = "Realisasi melebihi advance Rp " . number_format(abs($remaining), 0, ',', '.') . ". Butuh persetujuan Finance.";
            $result['actions'] = ['approve_overrun'];
        }

        return $result;
    }

    /**
     * Get status summary for UI display
     *
     * @param Advance $advance
     * @return array
     */
    public function getStatusSummary(Advance $advance): array
    {
        $evaluation = $this->evaluateStatus($advance);

        return [
            'status' => $evaluation['status'],
            'message' => $evaluation['message'],
            'total_realisasi' => $evaluation['total_realisasi'],
            'remaining' => $evaluation['remaining'],
            'progress' => $this->getProgressPercentage($advance),
            'can_return' => in_array('return', $evaluation['actions']) && $this->canUserProcessReturn(),
            'can_approve_overrun' => in_array('approve_overrun', $evaluation['actions']) && $this->canUserApproveOverrun(),
            'can_finish' => in_array('finish', $evaluation['actions']) && $this->canUserFinish(),
        ];
    }

    /**
     * Check if current user can process return
     *
     * @return bool
     */
    public function canUserProcessReturn(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        return $user && $user->hasRole(['Finance', 'Director', 'super_admin']);
    }

    /**
     * Check if current user can approve overrun
     *
     * @return bool
     */
    public function canUserApproveOverrun(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        return $user && $user->hasRole(['Finance', 'Director', 'super_admin']);
    }

    /**
     * Check if current user can finish advance
     *
     * @return bool
     */
    public function canUserFinish(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        return $user && $user->hasRole(['Finance', 'Director', 'super_admin']);
    }

    /**
     * Process user return money (create Transaction IN)
     *
     * @param Advance $advance
     * @param string|null $attachmentPath
     * @return Transaction
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function processReturn(Advance $advance, ?string $attachmentPath = null): Transaction
    {
        $remaining = $this->getRemainingAmount($advance);

        if ($remaining <= 0) {
            throw new \InvalidArgumentException(self::ERROR_NO_RETURN_NEEDED);
        }

        // Validate advance can have return processed
        if (!$this->canUserProcessReturn()) {
            throw new \InvalidArgumentException('Anda tidak memiliki izin untuk memproses return.');
        }

        return DB::transaction(function () use ($advance, $remaining, $attachmentPath) {
            // Create return transaction
            $transaction = Transaction::create([
                'payment_account_id' => $advance->payment_account_id,
                'created_by' => Auth::user()?->id,
                'type' => 'in',
                'amount' => $remaining,
                'description' => "Return Advance #{$advance->id}: {$advance->keterangan_advance}",
                'reference_type' => 'advance_return',
                'reference_id' => $advance->id,
                'transaction_date' => now()->toDateString(),
                'category' => 'other',
                'attachment' => $attachmentPath,
            ]);

            // Sync account balance
            if ($advance->paymentAccount) {
                $advance->paymentAccount->syncBalanceFromTransactions();
            }

            // Update advance status
            $advance->update(['status' => Advance::STATUS_SELESAI]);

            return $transaction;
        });
    }

    /**
     * Approve overrun
     *
     * @param Advance $advance
     * @throws \InvalidArgumentException
     */
    public function approveOverrun(Advance $advance): void
    {
        // Validate user can approve
        if (!$this->canUserApproveOverrun()) {
            throw new \InvalidArgumentException('Anda tidak memiliki izin untuk menyetujui overrun.');
        }

        // Check if there's actually an overrun
        $remaining = $this->getRemainingAmount($advance);
        if ($remaining >= 0) {
            throw new \InvalidArgumentException('Tidak ada overrun yang perlu disetujui.');
        }

        $advance->update([
            'overrun_approved_by' => Auth::user()?->id,
            'overrun_approved_at' => now(),
            'status' => Advance::STATUS_READY_TO_CLOSE,
        ]);
    }

    /**
     * Check if advance is ready to close
     *
     * @param Advance $advance
     * @return bool
     */
    public function isReadyToClose(Advance $advance): bool
    {
        // If overrun was approved, it's ready
        if ($advance->overrun_approved_by) {
            return true;
        }

        // If exact match (within tolerance)
        $totalRealisasi = $this->getTotalRealisasi($advance);
        $remaining = (float) $advance->paid - $totalRealisasi;
        return abs($remaining) < self::EXACT_MATCH_TOLERANCE;
    }

    /**
     * Finish advance (mark as Selesai)
     *
     * @param Advance $advance
     * @throws \InvalidArgumentException
     */
    public function finishAdvance(Advance $advance): void
    {
        // Validate advance is ready to close
        if (!$this->isReadyToClose($advance)) {
            throw new \InvalidArgumentException('Advance belum siap untuk diselesaikan.');
        }

        // Validate user can finish
        if (!$this->canUserFinish()) {
            throw new \InvalidArgumentException('Anda tidak memiliki izin untuk menyelesaikan advance.');
        }

        $evaluation = $this->evaluateStatus($advance);

        $notes = match ($evaluation['status']) {
            'exact_match' => 'Exact Match - Tidak ada sisa',
            'under_run' => 'Under-run - Sisa dikembalikan',
            'over_run' => 'Over-run - Disetujui Finance',
            default => 'Completed',
        };

        $advance->update([
            'status' => Advance::STATUS_SELESAI,
            'realisasi_notes' => $notes,
            'realisasi_at' => now(),
            'realisasi_by' => Auth::user()?->id,
        ]);
    }

    /**
     * Delete a batch and all related items and files
     *
     * @param Realisasi $batch
     * @throws \InvalidArgumentException
     */
    public function deleteBatch(Realisasi $batch): void
    {
        $advance = $batch->advance;

        // Validate advance can be modified
        if (!$advance->isEditable()) {
            throw new \InvalidArgumentException(self::ERROR_ADVANCE_COMPLETED);
        }

        if (!$advance->canRealisasi()) {
            throw new \InvalidArgumentException(self::ERROR_ADVANCE_NOT_PAYABLE);
        }

        DB::transaction(function () use ($batch) {
            // Delete files from storage
            foreach ($batch->items as $item) {
                foreach ($item->files as $file) {
                    Storage::delete($file->filepath);
                }
            }

            // Delete batch (cascade will delete items and files)
            $batch->delete();
        });
    }

    /**
     * Get all batches for an advance with items and files
     *
     * @param Advance $advance
     * @return \Illuminate\Support\Collection
     */
    public function getBatchesWithDetails(Advance $advance): \Illuminate\Support\Collection
    {
        return Realisasi::with(['items.files', 'uploadedBy'])
            ->where('advance_id', $advance->id)
            ->orderBy('batch_number')
            ->get();
    }

    /**
     * Update item
     *
     * @param RealisasiItem $item
     * @param array $data
     * @return RealisasiItem
     */
    public function updateItem(RealisasiItem $item, array $data): RealisasiItem
    {
        $item->update([
            'name' => $data['name'] ?? $item->name,
            'description' => $data['description'] ?? $item->description,
            'quantity' => $data['quantity'] ?? $item->quantity,
            'price' => $data['price'] ?? $item->price,
        ]);

        return $item->fresh();
    }

    /**
     * Add files to an existing item
     *
     * @param RealisasiItem $item
     * @param array $uploadedFiles
     */
    public function addFiles(RealisasiItem $item, array $uploadedFiles): void
    {
        $this->attachFiles($item, $uploadedFiles);
    }

    /**
     * Delete a file
     *
     * @param RealisasiFile $file
     */
    public function deleteFile(RealisasiFile $file): void
    {
        Storage::delete($file->filepath);
        $file->delete();
    }

    /**
     * Get summary statistics for an advance
     *
     * @param Advance $advance
     * @return array
     */
    public function getSummary(Advance $advance): array
    {
        $batches = $this->getBatchesWithDetails($advance);

        return [
            'batch_count' => $batches->count(),
            'item_count' => $batches->sum(fn($b) => $b->items->count()),
            'file_count' => $batches->sum(fn($b) => $b->items->sum(fn($i) => $i->files->count())),
            'total_realisasi' => $this->getTotalRealisasi($advance),
            'remaining' => $this->getRemainingAmount($advance),
            'progress_percentage' => $this->getProgressPercentage($advance),
            'status' => $this->evaluateStatus($advance),
        ];
    }
}