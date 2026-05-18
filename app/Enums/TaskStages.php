<?php

namespace App\Enums;
use Mokhosh\FilamentKanban\Concerns\IsKanbanStatus;

enum TaskStages: string

{

    use IsKanbanStatus;

    case Log = 'Log';
    case Process = 'Process';
    case Review = 'Review';
    case Done = 'Done';
    case Hold = 'Hold';
}
