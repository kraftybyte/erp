<?php

namespace App\Filament\Resources\ContractResource\Actions;

use App\Filament\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Deal;
use App\Services\ContractDuplicator;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;

/**
 * Tombol "Duplikat" (daftar kontrak, View, Edit): salin seluruh isi kontrak
 * ke kontrak baru, ganti nomor/klien/tanggal di modal, lalu buka halaman
 * edit kontrak baru. Izin Shield: replicate_contract dan create_contract.
 */
final class DuplicateContract
{
    public static function page(): PageAction
    {
        return self::configure(PageAction::make('duplicate'));
    }

    public static function table(): TableAction
    {
        return self::configure(TableAction::make('duplicate'))->hiddenLabel()->tooltip('Duplikat');
    }

    /**
     * @template T of PageAction|TableAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configure(PageAction|TableAction $action): PageAction|TableAction
    {
        return $action
            ->label('Duplikat')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->authorize(fn (Contract $record): bool => auth()->user()->can('replicate', $record) && auth()->user()->can('create', Contract::class))
            ->modalHeading('Duplikat kontrak')
            ->modalDescription(fn (Contract $record): string => "Semua isi kontrak {$record->contract_number} disalin: pasal, ruang lingkup, lampiran, kop surat, dan penanda tangan. Setelah itu halaman edit kontrak baru terbuka.")
            ->modalSubmitActionLabel('Duplikat')
            ->modalWidth('lg')
            ->fillForm(fn (Contract $record): array => [
                'contract_number'     => Contract::generateContractNumber(),
                'deal_id'             => $record->deal_id,
                'client_name_full'    => $record->client_name_full,
                'copy_client_details' => true,
                'effective_date'      => today()->toDateString(),
            ])
            ->form(fn (Contract $record): array => [
                TextInput::make('contract_number')
                    ->label('Nomor kontrak baru')
                    ->required()
                    ->maxLength(255),
                Select::make('deal_id')
                    ->label('Deal')
                    ->options(fn () => Deal::query()->latest('id')->limit(1000)->pluck('nama_perusahaan', 'id')->filter()->all())
                    ->searchable()
                    ->placeholder('Tanpa deal')
                    ->helperText('Pilih deal client lain untuk mengisi nama, alamat, telepon, email, dan PIC dari deal itu.')
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) use ($record): void {
                        $deal = filled($state) ? Deal::find($state) : null;

                        if ($deal && (int) $deal->getKey() !== (int) $record->deal_id) {
                            $set('client_name_full', $deal->nama_perusahaan);
                        }
                    }),
                TextInput::make('client_name_full')
                    ->label('Nama perusahaan client')
                    ->required()
                    ->maxLength(255),
                Toggle::make('copy_client_details')
                    ->label('Salin juga alamat, telepon, email, dan penanda tangan client')
                    ->helperText('Matikan bila kontrak ini untuk client lain, lalu isi datanya di halaman edit.')
                    ->visible(fn (Get $get): bool => ! self::dealChanged($get('deal_id'), $record)),
                Placeholder::make('client_from_deal')
                    ->hiddenLabel()
                    ->content('Alamat, telepon, email, dan PIC client diisi dari deal yang dipilih.')
                    ->visible(fn (Get $get): bool => self::dealChanged($get('deal_id'), $record)),
                DatePicker::make('effective_date')
                    ->label('Tanggal efektif')
                    ->required()
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->helperText($record->expiration_date ? 'Tanggal berakhir ikut bergeser dengan durasi yang sama.' : null),
            ])
            ->action(function (Contract $record, array $data, $livewire): void {
                $copy = app(ContractDuplicator::class)->duplicate($record, $data);

                Notification::make()
                    ->title("Kontrak diduplikat: {$copy->contract_number}")
                    ->body('Periksa data client dan isi kontrak, lalu simpan.')
                    ->success()
                    ->send();

                $livewire->redirect(ContractResource::getUrl('edit', ['record' => $copy]));
            });
    }

    private static function dealChanged(mixed $dealId, Contract $record): bool
    {
        return filled($dealId) && (int) $dealId !== (int) $record->deal_id;
    }
}
