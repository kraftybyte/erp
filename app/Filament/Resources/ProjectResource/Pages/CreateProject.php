<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\ProjectResource;
use App\Models\Invoice;
use App\Services\ProjectFromInvoice;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    /**
     * Invoice asal bila dibuka dari tombol "Buat Project" (?invoice=ID). Tidak
     * bisa diubah dari browser.
     */
    #[Locked]
    public ?int $fromInvoice = null;

    public ?string $fromInvoiceNumber = null;

    public function mount(): void
    {
        parent::mount();

        $invoice = Invoice::with('invoicesItems')->find(request()->integer('invoice'));

        if ($invoice && InvoiceResource::canView($invoice)) {
            $this->fromInvoice       = $invoice->id;
            $this->fromInvoiceNumber = $invoice->uuid;
            $this->form->fill(ProjectFromInvoice::formData($invoice));
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->fromInvoice
            ? "Dari invoice {$this->fromInvoiceNumber}: client dan detail sudah terisi. Pilih Kategori Project, cek tanggal, lalu simpan."
            : null;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['invoice_id'] = $this->fromInvoice;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

}
