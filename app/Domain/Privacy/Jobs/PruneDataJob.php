<?php

namespace App\Domain\Privacy\Jobs;

use App\Ai\Runs\AiRun;
use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OfferSnapshot;
use App\Domain\Relations\Models\Contact;
use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Models\RawPosting;
use App\Settings\PrivacySettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Nachtelijke opschoning volgens de bewaartermijnen (§19.3, instelbaar in Instellingen →
 * Privacy). Elke run komt met de aantallen in het audit-log. Snapshots worden alleen hier
 * verwijderd (R8).
 */
class PruneDataJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    /**
     * @return array<string, int>
     */
    public function handle(PrivacySettings $settings): array
    {
        $counts = [
            'ruwe_publicaties' => RawPosting::query()
                ->where('received_at', '<', now()->subDays($settings->raw_postings_days))
                ->where('status', '!=', RawPostingStatus::Pending->value)
                ->delete(),
            'aanbiedingen' => $this->pruneOffers($settings),
            'matches' => CandidateMatch::query()
                ->where('updated_at', '<', now()->subMonths($settings->matches_months))
                ->whereDoesntHave('offers')
                ->whereHas('assignment', fn ($query) => $query->whereIn('status', [AssignmentStatus::Gesloten->value, AssignmentStatus::Genegeerd->value]))
                ->delete(),
            'opdrachten' => $this->pruneAssignments($settings),
            'contactpersonen' => Contact::query()
                ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMonths($settings->contacts_months)))
                ->where('created_at', '<', now()->subMonths($settings->contacts_months))
                ->whereDoesntHave('offers', fn ($query) => $query->where('created_at', '>=', now()->subMonths($settings->contacts_months)))
                ->delete(),
            'ai_taken' => AiRun::query()->where('queued_at', '<', now()->subMonths($settings->ai_runs_months))->delete(),
            'website_onderzoek' => Organization::query()
                ->whereNotNull('researched_at')
                ->where('researched_at', '<', now()->subDays($settings->web_research_days))
                ->update(['research' => null, 'researched_at' => null]),
        ];

        if (array_sum($counts) > 0) {
            activity('privacy')->withProperties($counts)->log('Bewaartermijnen toegepast');
        }

        return $counts;
    }

    /**
     * Afgeronde aanbiedingen ouder dan de termijn, met snapshot, CV's, mails en bestanden. Bij een
     * plaatsing telt de termijn vanaf de afronding (§19.3: zolang de plaatsing loopt + termijn).
     */
    private function pruneOffers(PrivacySettings $settings): int
    {
        $offers = Offer::query()
            ->whereIn('status', [OfferStatus::Afgewezen->value, OfferStatus::Ingetrokken->value, OfferStatus::GeenReactie->value, OfferStatus::Geplaatst->value])
            ->where(fn ($query) => $query->where('closed_at', '<', now()->subMonths($settings->offers_months))
                ->orWhere(fn ($query) => $query->whereNull('closed_at')->where('updated_at', '<', now()->subMonths($settings->offers_months))))
            ->get();

        foreach ($offers as $offer) {
            DB::transaction(function () use ($offer): void {
                OfferSnapshot::$allowPruning = true;

                try {
                    $offer->snapshot?->delete();
                } finally {
                    OfferSnapshot::$allowPruning = false;
                }

                $offer->tailoredCvs()->delete();
                $offer->emails()->delete();
                $offer->delete();
            });

            Storage::disk('local')->deleteDirectory("offers/{$offer->id}");
        }

        return $offers->count();
    }

    /**
     * Gesloten of genegeerde opdrachten zonder aanbieding, ouder dan de termijn.
     */
    private function pruneAssignments(PrivacySettings $settings): int
    {
        return Assignment::query()
            ->whereIn('status', [AssignmentStatus::Gesloten->value, AssignmentStatus::Genegeerd->value])
            ->where('updated_at', '<', now()->subMonths($settings->closed_assignments_months))
            ->whereNotIn('id', Offer::query()->select('assignment_id'))
            ->delete();
    }
}
