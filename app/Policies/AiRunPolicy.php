<?php

namespace App\Policies;

use App\Ai\Runs\AiRun;
use App\Enums\Role;
use App\Models\User;

/**
 * AI-taken zijn alleen-lezen in de UI; ze ontstaan uitsluitend via de RunTracker (R10).
 */
class AiRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->hasRole(Role::Reviewer->value);
    }

    public function view(User $user, AiRun $run): bool
    {
        return $user->isBeheerder() || $run->started_by === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AiRun $run): bool
    {
        return false;
    }

    public function delete(User $user, AiRun $run): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
