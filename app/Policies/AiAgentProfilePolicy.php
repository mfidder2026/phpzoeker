<?php

namespace App\Policies;

use App\Ai\Config\AiAgentProfile;
use App\Models\User;

/**
 * Alleen de rol Beheerder kan de AI-agent (en daarmee de API-sleutel) zien en wijzigen (FR-AI4).
 */
class AiAgentProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isBeheerder();
    }

    public function view(User $user, AiAgentProfile $profile): bool
    {
        return $user->isBeheerder();
    }

    public function create(User $user): bool
    {
        return $user->isBeheerder();
    }

    public function update(User $user, AiAgentProfile $profile): bool
    {
        return $user->isBeheerder();
    }

    public function delete(User $user, AiAgentProfile $profile): bool
    {
        return $user->isBeheerder() && ! $profile->is_active;
    }
}
