<?php

namespace App\Filament\Resources\AdvanceResource\Pages;

use App\Filament\Resources\AdvanceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAdvance extends ViewRecord
{
    protected static string $resource = AdvanceResource::class;

    protected static string $view = 'filament.resources.advance-resource.pages.view-advance';
}
