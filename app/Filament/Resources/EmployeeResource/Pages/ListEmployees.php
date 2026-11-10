<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Concerns\ExportsTableToExcel;
use App\Filament\Resources\EmployeeResource;
use App\Models\User;
use App\Services\EmployeeExport;
use App\Services\EmployeeImport;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListEmployees extends ListRecords
{
    use ExportsTableToExcel;

    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Baris yang sedang tampil; kolom gaji hanya untuk yang boleh mengelola gaji.
            $this->excelExportAction('Data Karyawan', fn (Builder $query, array $filters) => app(EmployeeExport::class)->employees($query, $filters)),
            $this->importAction(),
            // Izin Shield create_user (UserPolicy), dulu hanya peran super_admin.
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Import karyawan (EmployeeImport): pilih file, periksa pratinjaunya, lalu
     * simpan. Izin Shield create_user / update_user per baris.
     */
    private function importAction(): Actions\Action
    {
        return Actions\Action::make('import')
            ->label('Import Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn () => (bool) (Auth::user()?->can('create', User::class) || Auth::user()?->can('update_user')))
            ->modalHeading('Import karyawan dari Excel')
            ->modalWidth(MaxWidth::FiveExtraLarge)
            ->modalSubmitActionLabel('Import')
            ->steps([
                Step::make('File')
                    ->schema([
                        Forms\Components\Placeholder::make('help')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                'Pakai file hasil <strong>Export Excel</strong> (ubah lalu impor lagi) atau '
                                . '<a href="' . e(route('import-template.employees')) . '" target="_blank" class="font-semibold text-primary-600 underline">unduh template</a>. '
                                . 'Karyawan dicocokkan lewat NIK: yang sudah ada diperbarui (sel kosong tidak mengubah data), yang belum ada dibuat baru. '
                                . 'Sheet Komponen Gaji ikut diimpor bila kamu boleh mengelola gaji.'
                            )),
                        Forms\Components\FileUpload::make('file')
                            ->label('File Excel / CSV')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'application/vnd.oasis.opendocument.spreadsheet',
                                'application/zip',
                                'application/octet-stream',
                                'text/csv',
                                'text/plain',
                            ])
                            ->maxSize(10240)
                            ->storeFiles(false)
                            ->required()
                            ->live(),
                    ]),
                Step::make('Periksa')
                    ->schema([
                        Forms\Components\Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => $this->preview($get('file'))),
                    ]),
            ])
            ->action(function (array $data) {
                $file = self::uploaded($data['file'] ?? null);

                if (! $file) {
                    return;
                }

                $plan = app(EmployeeImport::class)->plan($file->getRealPath(), Auth::user());

                if ($plan['error']) {
                    Notification::make()->title('Tidak ada yang diimpor')->body($plan['error'])->danger()->send();

                    return;
                }

                $counts = app(EmployeeImport::class)->import($file->getRealPath(), Auth::user());

                Notification::make()
                    ->title('Import karyawan selesai')
                    ->body("{$counts['created']} baru · {$counts['updated']} diperbarui · {$counts['unchanged']} tanpa perubahan · {$counts['skipped']} dilewati"
                        . ($counts['components'] ? " · {$counts['components']} komponen gaji" : ''))
                    ->success()
                    ->send();
            });
    }

    /**
     * Ringkasan dan tabel per baris: baru, diperbarui, tanpa perubahan, atau dilewati beserta alasannya.
     */
    private function preview(mixed $state): HtmlString
    {
        $file = self::uploaded($state);

        if (! $file) {
            return new HtmlString('<p>Pilih file dulu di langkah sebelumnya.</p>');
        }

        $plan = app(EmployeeImport::class)->plan($file->getRealPath(), Auth::user());

        if ($plan['error']) {
            return new HtmlString('<p style="color: rgb(var(--danger-600)); font-weight: 600;">' . e($plan['error']) . '</p>');
        }

        $rows   = collect($plan['rows']);
        $labels = [
            'create' => ['Baru', 'success'],
            'update' => ['Diperbarui', 'info'],
            'same'   => ['Tanpa perubahan', 'gray'],
            'skip'   => ['Dilewati', 'danger'],
        ];

        $summary = collect($labels)->map(fn ($label, $action) => $rows->where('action', $action)->count() . ' ' . Str::lower($label[0]))->implode(' · ');
        $components = collect($plan['components'])->map(fn ($items) => count($items))->sum();

        $html = '<p style="margin-bottom: .75rem; font-weight: 600;">' . e($summary)
            . ($components ? ' · ' . $components . ' komponen gaji untuk ' . count($plan['components']) . ' karyawan' : '') . '</p>';

        $html .= '<div style="max-height: 24rem; overflow: auto; border: 1px solid rgba(var(--gray-500), .25); border-radius: .5rem;">'
            . '<table style="width: 100%; font-size: .8125rem; border-collapse: collapse;">'
            . '<thead><tr style="text-align: left; background: rgba(var(--gray-500), .08);">'
            . '<th style="padding: .5rem;">Baris</th><th style="padding: .5rem;">NIK</th><th style="padding: .5rem;">Nama</th><th style="padding: .5rem;">Hasil</th><th style="padding: .5rem;">Rincian</th></tr></thead><tbody>';

        foreach ($rows->sortBy(fn ($row) => $row['action'] === 'skip' ? 0 : 1)->take(200) as $row) {
            [$label, $color] = $labels[$row['action']];

            $html .= '<tr style="border-top: 1px solid rgba(var(--gray-500), .15); vertical-align: top;">'
                . '<td style="padding: .5rem;">' . $row['row'] . '</td>'
                . '<td style="padding: .5rem; font-variant-numeric: tabular-nums;">' . e($row['nik']) . '</td>'
                . '<td style="padding: .5rem;">' . e($row['name']) . '</td>'
                . '<td style="padding: .5rem; font-weight: 600; color: rgb(var(--' . $color . '-600));">' . e($label) . '</td>'
                . '<td style="padding: .5rem;">' . e($row['reason'] ?? implode(' · ', $row['changes'])) . '</td></tr>';
        }

        $html .= '</tbody></table></div>';

        foreach ($plan['notes'] as $note) {
            $html .= '<p style="margin-top: .5rem; font-size: .8125rem; color: rgb(var(--warning-700));">' . e($note) . '</p>';
        }

        return new HtmlString($html . '<p style="margin-top: .75rem; font-size: .8125rem;">Tekan <strong>Import</strong> untuk menyimpan baris yang tidak dilewati.</p>');
    }

    private static function uploaded(mixed $state): ?TemporaryUploadedFile
    {
        $file = is_array($state) ? reset($state) : $state;

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();
        $user = Auth::user();

        if (!$user->hasRole('super_admin')) {
            $query->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'super_admin');
            });
        }

        return $query;
    }
}
