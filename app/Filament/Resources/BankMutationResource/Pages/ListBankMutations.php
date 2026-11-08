<?php

namespace App\Filament\Resources\BankMutationResource\Pages;

use App\Filament\Resources\BankMutationResource;
use Filament\Resources\Pages\ListRecords;

class ListBankMutations extends ListRecords
{
    protected static string $resource = BankMutationResource::class;

    public function getSubheading(): ?string
    {
        return 'Mutasi yang dicatat lewat bot keuangan: kirim file mutasi rekening ke bot dengan perintah /mutasi.';
    }
}
