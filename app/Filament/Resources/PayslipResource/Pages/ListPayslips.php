<?php

namespace App\Filament\Resources\PayslipResource\Pages;

use App\Filament\Resources\PayslipResource;
use App\Helpers\Money;
use App\Services\Payroll;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListPayslips extends ListRecords
{
    protected static string $resource = PayslipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate')
                ->label('Buat slip')
                ->icon('heroicon-o-document-plus')
                ->visible(fn () => (bool) auth()->user()?->can('create', \App\Models\Payslip::class))
                ->modalHeading('Buat slip gaji')
                ->modalDescription('Slip draft dibuat dari komponen gaji setiap karyawan aktif. Karyawan yang sudah punya slip bulan itu dilewati.')
                ->form([
                    Forms\Components\Select::make('period')
                        ->label('Bulan')
                        ->options(fn () => collect(range(-3, 1))
                            ->mapWithKeys(fn (int $offset) => [
                                now()->startOfMonth()->addMonthsNoOverflow($offset)->toDateString() => now()->startOfMonth()->addMonthsNoOverflow($offset)->locale('id')->translatedFormat('F Y'),
                            ])->all())
                        ->default(now()->startOfMonth()->toDateString())
                        ->required(),
                ])
                ->action(function (array $data) {
                    $created = app(Payroll::class)->generate(Carbon::parse($data['period']));

                    Notification::make()
                        ->title($created->isEmpty() ? 'Tidak ada slip baru' : $created->count() . ' slip draft dibuat')
                        ->body($created->isEmpty()
                            ? 'Semua karyawan yang punya komponen gaji sudah punya slip bulan itu.'
                            : 'Total take home pay ' . Money::rupiah($created->sum('net')) . '. Periksa, tambah penyesuaian bila perlu, lalu Final & kirim.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
