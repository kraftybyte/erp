<?php

namespace App\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use TomatoPHP\FilamentInvoices\Models\Invoice;

class RecentInvoicesTable extends BaseWidget
{
    
    
     protected int|string|array $columnSpan = 'full';

    

        public static function canView(): bool
    {
        return false;
    }


    public function table(Table $table): Table
    {
        return $table
            ->query(
                Invoice::query()
                    ->latest()
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('uuid')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('nama_perusahaan')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('grand_total')
                    ->label('Total')
                    ->money('IDR')
                    ->sortable(),
                    
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'paid',
                        'warning' => 'pending',
                        'danger' => 'cancelled',
                        'secondary' => 'draft',
                    ])
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->date()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}