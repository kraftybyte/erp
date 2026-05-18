<?php

namespace App\Notifications;

use App\Models\PurchaseRequest;
use Illuminate\Notifications\Notification;

class PurchaseRequestApprovalNotification extends Notification
{
    public function __construct(
        public PurchaseRequest $purchaseRequest,
        public string $status
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $isApproved = $this->status === 'Disetujui';
        $itemName = $this->purchaseRequest->item_name ?? 'Item';
        $price = number_format($this->purchaseRequest->price ?? 0, 0, ',', '.');

        return [
            'title' => $isApproved ? 'Purchase Request Disetujui' : 'Purchase Request Ditolak',
            'body' => "Permintaan {$itemName} (Rp {$price}) telah " . ($isApproved ? 'disetujui' : 'ditolak'),
            'icon' => $isApproved ? 'heroicon-o-check-circle text-success' : 'heroicon-o-x-circle text-danger',
            'url' => '/admin/purchase-requests',
        ];
    }
}