<?php

namespace App\Filament\Resources\LeadsResource\Pages;

use App\Filament\Resources\LeadsResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewLeads extends ViewRecord
{
    protected static string $resource = LeadsResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\TextEntry::make('nama_pic')
                    ->label('Nama PIC'),

                Infolists\Components\TextEntry::make('nama_perusahaan')
                    ->label('Nama Perusahaan'),

                Infolists\Components\TextEntry::make('email')
                    ->label('Email'),

                Infolists\Components\TextEntry::make('telephone')
                    ->label('Telepon'),

                Infolists\Components\TextEntry::make('source')
                    ->label('Sumber'),

                Infolists\Components\TextEntry::make('status')
                    ->label('Status Leads'),

                Infolists\Components\TextEntry::make('product.nama_layanan')
                    ->label('Layanan')
                    ->listWithLineBreaks()
                    ->bulleted(),
            ]);
    }
}
