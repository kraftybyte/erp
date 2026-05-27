<?php

namespace App\Observers;

use App\Models\Quotation;
use App\Models\User;
use App\Helpers\NotifHelper;

class QuotationObserver
{
    /**
     * Handle the Quotation "created" event.
     */
    public function created(Quotation $quotation): void
    {
        $url = '/admin/quotations';

        // Notify Directors when new quotation is created
        $recipients = User::role(['Director', 'Finance', 'super_admin'])->get();
        $nominal = 'Rp ' . number_format($quotation->grand_total ?? 0, 0, ',', '.');

        NotifHelper::send(
            $recipients,
            'Quotation Baru 📄',
            "Quotation untuk {$quotation->nama_perusahaan} - {$nominal}",
            'heroicon-o-document-text',
            'info',
            $url
        );
    }

    /**
     * Handle the Quotation "updated" event.
     */
    public function updated(Quotation $quotation): void
    {
        $url = '/admin/quotations';

        // Status change notifications
        if ($quotation->wasChanged('status')) {
            $nominal = 'Rp ' . number_format($quotation->grand_total ?? 0, 0, ',', '.');

            match ($quotation->status) {
                'Approved' => NotifHelper::send(
                    User::role(['Director', 'Sales', 'super_admin'])->get(),
                    'Quotation Approved ✅',
                    "Quotation {$quotation->uuid} ({$quotation->nama_perusahaan}) telah di-approve - {$nominal}",
                    'heroicon-o-check-circle',
                    'success',
                    $url
                ),
                'Rejected' => NotifHelper::send(
                    User::role(['Director', 'Sales', 'super_admin'])->get(),
                    'Quotation Ditolak ❌',
                    "Quotation {$quotation->uuid} ({$quotation->nama_perusahaan}) ditolak.",
                    'heroicon-o-x-circle',
                    'danger',
                    $url
                ),
                'Converted' => NotifHelper::send(
                    User::role(['Director', 'Finance', 'super_admin'])->get(),
                    'Quotation Di-convert ke Invoice 📋',
                    "Quotation {$quotation->uuid} ({$quotation->nama_perusahaan}) di-convert - {$nominal}",
                    'heroicon-o-document-arrow-right',
                    'success',
                    $url
                ),
                default => null,
            };
        }
    }
}