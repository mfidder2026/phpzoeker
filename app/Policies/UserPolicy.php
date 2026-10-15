<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isBeheerder();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isBeheerder() || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->isBeheerder();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isBeheerder();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->isBeheerder() && ! $user->is($model);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isBeheerder();
    }
}
