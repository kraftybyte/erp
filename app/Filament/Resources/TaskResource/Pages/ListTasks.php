<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('board_view')
                ->label('Board')
                ->icon('heroicon-o-squares-2x2')
                ->url('/admin/task-kanban-board')
                ->color('gray')
                ->extraAttributes([
                    'style' => 'background:transparent; color:#64748b; box-shadow:none; border:none; padding:6px 12px;',
                ]),

            Actions\Action::make('list_view')
                ->label('List')
                ->icon('heroicon-o-bars-3')
                ->disabled()
                ->color('gray')
                ->extraAttributes([
                    'style' => 'background:white; color:#07a0cf; box-shadow:0 1px 3px rgba(0,0,0,0.1); border:none; padding:6px 12px; cursor:default; opacity:1;',
                ]),

            Actions\CreateAction::make(),
        ];
    }
}