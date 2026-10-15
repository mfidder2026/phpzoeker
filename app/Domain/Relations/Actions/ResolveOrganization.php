<?php

namespace App\Domain\Relations\Actions;

use App\Domain\Relations\Enums\OrganizationType;
use App\Domain\Relations\Models\Organization;

/**
 * Herkent of maakt een organisatie op genormaliseerde naam (§11.9). Samengevoegde
 * organisaties verwijzen door naar hun hoofdrecord.
 */
class ResolveOrganization
{
    public function handle(?string $name, OrganizationType $type): ?Organization
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $normalized = Organization::normalizeName($name);

        if ($normalized === '') {
            return null;
        }

        $organization = Organization::query()->where('normalized_name', $normalized)->first()
            ?? Organization::query()->create(['name' => trim($name), 'type' => $type]);

        while ($organization->merged_into_id !== null) {
            $next = Organization::query()->find($organization->merged_into_id);

            if ($next === null) {
                break;
            }

            $organization = $next;
        }

        return $organization;
    }
}
