<?php

namespace App\Policies;

use App\Models\User;
use App\Filament\Pages\ProjectTimelineBoard;
use Illuminate\Auth\Access\HandlesAuthorization;


class ProjectTimelineBoardPolicy
{
    /**
     * View page.
     */
    public function view(User $user): bool
    {
        return $user->can('view_project_timeline_board');
    }
}
