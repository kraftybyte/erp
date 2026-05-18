<?php

namespace App\Filament\Resources\DealResource\Pages;

use App\Filament\Resources\DealResource;
use Illuminate\Support\Facades\Gate;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section;

class ViewDeals extends ViewRecord
{
    protected static string $resource = DealResource::class;

    /* ================= HEADER ACTIONS ================= */

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('addProject')
                ->label('Tambah Project')
                ->icon('heroicon-o-rocket-launch')
                ->color('gray')
                ->url(fn () => route('filament.admin.resources.projects.create', [
                    'deal_id' => $this->record->deal_id,
                ]))
                ->hidden(fn ($record) => $record && !Gate::allows('edit-Deal', $record)),

            Actions\Action::make('addContract')
                ->label('Contract')
                ->icon('heroicon-s-plus')
                ->color('secondary')
                ->url(fn () => route('filament.admin.resources.contracts.create', [
                    'deal_id' => $this->record->deal_id,
                ]))
                ->hidden(fn ($record) => $record && !Gate::allows('edit-Deal', $record)),

            Actions\Action::make('addInvoice')
                ->label('Invoice')
                ->icon('heroicon-s-plus')
                ->color('primary')
                ->url(fn () => route('filament.admin.resources.invoices.create', [
                    'nama_perusahaan' => $this->record->nama_perusahaan,
                ]))
                ->hidden(fn ($record) => $record && !Gate::allows('edit-Deal', $record)),

            Action::make('addQuotations')
                ->label('Quotations')
                ->icon('heroicon-s-plus')
                ->color('danger')
                ->url(fn () => route('filament.admin.resources.quotations.create'))
                ->hidden(fn ($record) => $record && !Gate::allows('edit-Deal', $record)),

            Action::make('EditDeals')
                ->label('Edit')
                ->icon('heroicon-s-pencil')
                ->color('info')
                ->url(fn () => route('filament.admin.resources.deals.edit', $this->record))
                ->hidden(fn ($record) => $record && !Gate::allows('edit-Deal', $record)),
        ];
    }

    /* ================= INFOLIST ================= */

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([

            /* ========= HEADER CARD ========= */

            Section::make()
                ->schema([
                    Grid::make(2)
                        ->schema([
                            TextEntry::make('nama_perusahaan')
                                ->label('')
                                ->weight('bold')
                                ->size(TextEntry\TextEntrySize::Large),

                            TextEntry::make('status')
                                ->label('')
                                ->badge()
                                ->alignRight()
                                ->color(fn (string $state): string => match ($state) {
                                    'Negosiasi' => 'gray',
                                    'Menunggu Pembayaran' => 'warning',
                                    'Dalam Tahap Project' => 'success',
                                    'Selesai' => 'primary',
                                    'Hold' => 'danger',
                                    default => 'secondary',
                                }),
                        ]),
                ])
                ->extraAttributes([
                    'class' => 'bg-gray-50 dark:bg-gray-800 rounded-xl'
                ]),

            /* ========= INFORMASI KLIEN ========= */

            Section::make('Informasi Klien')
                ->icon('heroicon-o-user-circle')
                ->columns(3)
                ->schema([
                    TextEntry::make('nama_pic')
                        ->label('Nama PIC'),

                    TextEntry::make('email')
                        ->label('Email'),

                    TextEntry::make('telephone')
                        ->label('Telepon'),

                    TextEntry::make('NPWP')
                        ->label('NPWP'),

                    TextEntry::make('source')
                        ->label('Sumber Client'),

                    TextEntry::make('created_at')
                        ->label('Tanggal Deal')
                        ->date('d M Y'),
                ]),

            /* ========= ALAMAT ========= */

            Section::make('Alamat')
                ->icon('heroicon-o-map-pin')
                ->collapsible()
                ->schema([
                    TextEntry::make('alamat')
                        ->label('')
                        ->columnSpanFull(),
                ]),

            /* ========= LAYANAN ========= */

            Section::make('Layanan')
                ->icon('heroicon-o-briefcase')
                ->schema([
                    TextEntry::make('product.nama_layanan')
                        ->badge()
                        ->color('primary')
                        ->extraAttributes(['class' => 'text-sm px-3 py-1 rounded-full'])
                        ->listWithLineBreaks(),
                ]),

            /* ========= CATATAN ========= */

            Section::make('Catatan')
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->schema([
                    TextEntry::make('notes')
                        ->markdown()
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
