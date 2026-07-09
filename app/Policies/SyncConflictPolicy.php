<?php

namespace App\Policies;

use App\Models\SyncConflict;
use App\Models\User;

class SyncConflictPolicy
{
    public function view(User $user, SyncConflict $syncConflict): bool
    {
        return $user->isAdmin() || $syncConflict->user_id === $user->id;
    }

    public function resolve(User $user, SyncConflict $syncConflict): bool
    {
        return $user->isAdmin() || $syncConflict->user_id === $user->id;
    }
}
