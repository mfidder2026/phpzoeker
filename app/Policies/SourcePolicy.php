<?php

namespace App\Policies;

use App\Domain\Sources\Models\Source;
use App\Models\User;

/**
 * Bronnen (inclusief versleutelde mailboxgegevens) zijn alleen voor de beheerder (§5).
 */
class SourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isBeheerder();
    }

    public function view(User $user, Source $source): bool
    {
        return $user->isBeheerder();
    }

    public function create(User $user): bool
    {
        return $user->isBeheerder();
    }

    public function update(User $user, Source $source): bool
    {
        return $user->isBeheerder();
    }

    public function delete(User $user, Source $source): bool
    {
        return $user->isBeheerder() && ! $source->rawPostings()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
