<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
            Tables\Columns\TextColumn::make('created_at')
                ->label('Tanggal Bergabung')
                ->date(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
