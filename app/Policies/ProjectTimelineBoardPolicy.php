<?php

namespace App\Policies;

use App\Models\User;
use App\Filament\Pages\ProjectTimelineBoard;

class ProjectTimelineBoardPolicy
{
    /**
     * View page.
     */
    public function viewAny(User $user): bool
    {
        // Allow super_admin or specific roles
        return $user->hasRole(['super_admin', 'Director', 'Project Manager', 'Creative', 'Admin']);
    }
}
