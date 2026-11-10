<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Helpers\Money;
use App\Models\PayrollComponent;
use App\Models\User;
use App\Services\Payroll;
use Filament\Forms\Get;
use App\Filament\Concerns\GuardsUserDeletion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('employee', 'Employee');
    }

    protected static ?string $navigationLabel = 'Employee';
    protected static ?int $navigationSort = -0;
    

    public static function getNavigationGroup(): ?string
    {
        return __("HR");
    }

    public static function getBreadcrumb(): string
    {
        return 'Employee'; // Ganti dengan nama breadcrumb yang Anda inginkan
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nik')
                ->label('NIK')
                ->unique(ignoreRecord: true)
                ->required()
                ->maxLength(16),
            Forms\Components\TextInput::make('firstname')
                ->label('Nama Depan')
                ->required(),
            Forms\Components\TextInput::make('lastname')
                ->label('Nama Belakang')
                ->required(),
            Forms\Components\TextInput::make('email')
                ->email()
                // Email unik di tabel users: tanpa ini simpan gagal dengan error database.
                ->unique(ignoreRecord: true)
                ->required(),
            Forms\Components\TextInput::make('phone')
                ->label('Nomor HP')
                ->required()
                ->hint('Contoh: 6281234567890'),
            Forms\Components\Textarea::make('address')
                ->label('Alamat Lengkap')
                ->required(),
            Forms\Components\Select::make('position')
                ->label('Jabatan')
                ->options([
                    'Staff' => 'Staff',
                    'Supervisor' => 'Supervisor',
                    'Manager' => 'Manager',
                    'Director' => 'Director',
                ])
                ->searchable()
                ->required(),
            Forms\Components\TextInput::make('npwp')
                ->label('NPWP')
                ->unique(ignoreRecord: true)
                ->maxLength(20),
            Forms\Components\FileUpload::make('ktp')
                ->label('Upload KTP')
                ->directory('employee/ktp/' . now()->format('Y-m'))
                ->acceptedFileTypes(['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'])
                ->maxSize(2048)
                ->nullable()
                ->downloadable()
                ->openable(),
            Forms\Components\FileUpload::make('file_npwp')
                ->label('Upload NPWP')
                ->directory('employee/npwp/' . now()->format('Y-m'))
                ->acceptedFileTypes(['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'])
                ->maxSize(2048)
                ->nullable()
                ->downloadable()
                ->openable(),
            Forms\Components\Select::make('division')
                ->label('Divisi')
                ->options([
                    'Social Media Specialist' => 'Social Media Specialist',
                    'Finance' => 'Finance',
                    'HR' => 'HR',
                    'Graphic Design' => 'Graphic Design',
                    'Video Editor' => 'Video Editor',
                ])
                ->searchable()
                ->required(),
            Forms\Components\Toggle::make('is_active')
                ->label('Akun aktif')
                ->helperText('Matikan untuk karyawan yang sudah keluar: tidak bisa login ERP maupun memakai bot Telegram. Datanya tetap tersimpan.')
                ->default(true)
                // Akun sendiri tidak bisa dinonaktifkan, supaya tidak terkunci di luar.
                ->disabled(fn ($record) => $record?->is(auth()->user())),

            // Gaji hanya untuk Director/Finance/super_admin: bagian ini tidak tampil
            // (dan tidak disimpan) untuk peran lain yang boleh mengelola karyawan.
            Forms\Components\Section::make('Gaji')
                ->description('Komponen tetap setiap bulan. PPh 21 dan BPJS ditulis sebagai potongan. Penyesuaian sekali pakai (lembur, bonus) diisi di slip bulan itu, HR → Slip Gaji.')
                ->visible(fn () => Payroll::canManage(auth()->user()))
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Repeater::make('payrollComponents')
                        ->relationship()
                        ->label('Komponen gaji')
                        ->orderColumn('sort')
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('Komponen')
                                ->placeholder('mis. Gaji pokok, Tunjangan transport, BPJS Kesehatan, PPh 21')
                                ->required()
                                ->maxLength(100)
                                ->columnSpan(3),
                            Forms\Components\Select::make('type')
                                ->label('Jenis')
                                ->options(PayrollComponent::TYPES)
                                ->default(PayrollComponent::EARNING)
                                ->required()
                                ->native(false)
                                ->live()
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('amount')
                                ->label('Nominal per bulan')
                                ->numeric()
                                ->prefix('Rp')
                                ->minValue(0)
                                ->required()
                                ->live(onBlur: true)
                                ->columnSpan(2),
                        ])
                        ->columns(7)
                        ->defaultItems(0)
                        ->addActionLabel('Tambah komponen')
                        ->live(),
                    Forms\Components\Placeholder::make('take_home')
                        ->label('Take home pay per bulan')
                        ->content(function (Get $get) {
                            $items = collect($get('payrollComponents') ?? []);
                            $sum   = fn (string $type) => (float) $items->where('type', $type)->sum(fn ($item) => (float) ($item['amount'] ?? 0));

                            return $items->isEmpty()
                                ? 'Belum ada komponen: karyawan ini belum masuk slip gaji.'
                                : Money::rupiah($sum(PayrollComponent::EARNING) - $sum(PayrollComponent::DEDUCTION))
                                    . ' (pendapatan ' . Money::rupiah($sum(PayrollComponent::EARNING)) . ' − potongan ' . Money::rupiah($sum(PayrollComponent::DEDUCTION)) . ')';
                        }),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('payrollComponents'))
            ->columns([
                Tables\Columns\TextColumn::make('nik')
                ->label('NIK')
                ->sortable()
                ->searchable(),
            Tables\Columns\TextColumn::make('firstname')
                ->label('Nama'),
            Tables\Columns\TextColumn::make('position')
                ->label('Jabatan')
                ->sortable(),
            Tables\Columns\TextColumn::make('phone')
                ->label('Nomor HP'),
            Tables\Columns\TextColumn::make('npwp')
                ->label('NPWP')
                ->sortable(),
            Tables\Columns\TextColumn::make('division')
                ->label('Divisi')
                ->sortable(),
            Tables\Columns\TextColumn::make('take_home')
                ->label('Gaji (take home)')
                ->alignEnd()
                ->visible(fn () => Payroll::canManage(auth()->user()))
                ->state(function (User $record) {
                    $components = $record->payrollComponents;

                    return $components->isEmpty() ? '—' : Money::rupiah(
                        $components->where('type', PayrollComponent::EARNING)->sum('amount') - $components->where('type', PayrollComponent::DEDUCTION)->sum('amount')
                    );
                }),
            Tables\Columns\IconColumn::make('is_active')
                ->label('Aktif')
                ->boolean(),
            Tables\Columns\TextColumn::make('created_at')
                ->label('Tanggal Bergabung')
                ->date()
                ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('division')
                    ->label('Divisi')
                    ->options([
                        'Social Media Specialist' => 'Social Media Specialist',
                        'Finance' => 'Finance',
                        'HR' => 'HR',
                        'Graphic Design' => 'Graphic Design',
                        'Video Editor' => 'Video Editor',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Akun aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->hiddenLabel()->tooltip('Edit'),
                Tables\Actions\DeleteAction::make()->hiddenLabel()->tooltip('Delete')->before(fn ($action, $record) => GuardsUserDeletion::single($action, $record)),

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->before(fn ($action, $records) => GuardsUserDeletion::bulk($action, $records)),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
