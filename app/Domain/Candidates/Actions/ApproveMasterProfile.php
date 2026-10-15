<?php

namespace App\Domain\Candidates\Actions;

use App\Domain\Candidates\Data\CandidateProfileSchema;
use App\Domain\Candidates\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keurt een conceptversie van het master-profiel goed en maakt hem actief. De wijziging van
 * `active_profile_id` laat open matches opnieuw berekenen (FR-K5).
 */
class ApproveMasterProfile
{
    /**
     * @return list<string> validatiefouten; leeg = goedgekeurd
     */
    public function handle(CandidateProfile $profile, ?User $approver): array
    {
        $errors = CandidateProfileSchema::validate($profile->data);

        if ($errors !== []) {
            return $errors;
        }

        DB::transaction(function () use ($profile, $approver): void {
            $profile->forceFill([
                'approved_by' => $approver?->id,
                'approved_at' => now(),
                'skills_vocabulary' => CandidateProfileSchema::skillsVocabulary($profile->data),
            ])->save();

            $profile->candidate->update(['active_profile_id' => $profile->id]);
        });

        return [];
    }
}
