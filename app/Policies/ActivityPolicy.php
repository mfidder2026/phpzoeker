<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit-log: alleen-lezen, alleen voor de beheerder.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isBeheerder();
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->isBeheerder();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
