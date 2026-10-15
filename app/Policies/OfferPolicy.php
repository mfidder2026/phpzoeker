<?php

namespace App\Policies;

use App\Domain\Offers\Models\Offer;
use App\Enums\Role;
use App\Models\User;

/**
 * Aanbiedingen (§5): staf maakt, keurt goed en verstuurt; een reviewer ziet ze en beoordeelt CV
 * en mail maar verstuurt niet; een kandidaat ziet alleen de eigen aanbiedingen. Aanbiedingen
 * worden niet verwijderd (register, R8).
 */
class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->hasAnyRole([Role::Reviewer->value, Role::Kandidaat->value]);
    }

    public function view(User $user, Offer $offer): bool
    {
        return $user->isStaff() || $user->hasRole(Role::Reviewer->value) || ($user->hasRole(Role::Kandidaat->value) && $user->candidate_id === $offer->candidate_id);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Offer $offer): bool
    {
        return $user->isStaff();
    }

    /**
     * Versturen en goedkeuren alleen door Accountmanager of Beheerder (§13.5, R1).
     */
    public function send(User $user, Offer $offer): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Offer $offer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
