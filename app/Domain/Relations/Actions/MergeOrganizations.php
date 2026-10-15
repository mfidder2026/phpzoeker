<?php

namespace App\Domain\Relations\Actions;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Assignments\Models\AssignmentSource;
use App\Domain\Offers\Models\Offer;
use App\Domain\Relations\Models\Contact;
use App\Domain\Relations\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Voegt een dubbele relatie samen (§11.9): contactpersonen, aanbiedingen en verwijzingen gaan
 * naar de overgebleven organisatie; de dubbele krijgt `merged_into_id`.
 */
class MergeOrganizations
{
    public function handle(Organization $duplicate, Organization $target): void
    {
        if ($duplicate->is($target)) {
            return;
        }

        DB::transaction(function () use ($duplicate, $target): void {
            Contact::query()->where('organization_id', $duplicate->id)->update(['organization_id' => $target->id]);
            Offer::query()->where('organization_id', $duplicate->id)->update(['organization_id' => $target->id]);
            AssignmentSource::query()->where('organization_id', $duplicate->id)->update(['organization_id' => $target->id]);
            Assignment::query()->where('client_organization_id', $duplicate->id)->update(['client_organization_id' => $target->id]);
            Assignment::query()->where('intermediary_organization_id', $duplicate->id)->update(['intermediary_organization_id' => $target->id]);

            $target->forceFill([
                'email_domains' => array_values(array_unique([...($target->email_domains ?? []), ...($duplicate->email_domains ?? [])])),
                'website' => $target->website ?? $duplicate->website,
                'default_response_term_days' => $target->default_response_term_days ?? $duplicate->default_response_term_days,
            ])->save();

            $duplicate->forceFill(['merged_into_id' => $target->id])->save();

            activity('relaties')->performedOn($target)->withProperties(['samengevoegd' => $duplicate->name])->log("Relatie {$duplicate->name} samengevoegd met {$target->name}");
        });
    }
}
