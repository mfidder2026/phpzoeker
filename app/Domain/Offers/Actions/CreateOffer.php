<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Assignments\Enums\WorkMode;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Assignments\Models\AssignmentSource;
use App\Domain\Assignments\Support\TitleSimilarity;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Offers\Enums\ConsentStatus;
use App\Domain\Offers\Enums\OfferActivityType;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Exceptions\DuplicateOfferException;
use App\Domain\Offers\Models\Offer;
use App\Models\User;
use App\Settings\CvSettings;
use App\Settings\MailSettings;
use App\Settings\RegisterSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Start een aanbieding ("Aanbieden", §11.2) of registreert er een die buiten de app is gedaan
 * (§11.8). Blokkeert een tweede actieve aanbieding van dezelfde kandidaat in dezelfde
 * dedupe-groep (R7, §11.4).
 */
class CreateOffer
{
    public function __construct(
        private readonly CvSettings $cvSettings,
        private readonly MailSettings $mailSettings,
        private readonly RegisterSettings $registerSettings,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  extra velden (bijv. bij handmatig registreren)
     *
     * @throws DuplicateOfferException
     */
    public function handle(Assignment $assignment, Candidate $candidate, ?User $user, ?CandidateMatch $match = null, ?AssignmentSource $route = null, OfferChannel $channel = OfferChannel::Email, array $attributes = []): Offer
    {
        self::ensureNotOffered($candidate, $assignment);

        $route ??= self::defaultRoute($assignment);
        $match ??= CandidateMatch::query()->where('assignment_id', $assignment->id)->where('candidate_id', $candidate->id)->first();
        $candidate->loadMissing('absences');

        try {
            $offer = DB::transaction(function () use ($assignment, $candidate, $user, $match, $route, $channel, $attributes): Offer {
                $offer = Offer::query()->create([
                    'match_id' => $match?->id,
                    'assignment_id' => $assignment->id,
                    'candidate_id' => $candidate->id,
                    'dedupe_group_id' => $assignment->dedupe_group_id,
                    'source_id' => $route?->source_id,
                    'assignment_source_id' => $route?->id,
                    'organization_id' => $route->organization_id ?? $assignment->intermediary_organization_id ?? $assignment->client_organization_id,
                    'contact_id' => $route?->contact_id,
                    'channel' => $channel,
                    'status' => OfferStatus::Concept,
                    'candidate_snapshot' => $candidate->offerSnapshot(),
                    'cv_options' => $this->defaultCvOptions($assignment),
                    'offered_rate' => $candidate->hourly_rate,
                    'consent_status' => $this->mailSettings->require_candidate_consent && $channel !== OfferChannel::Manual ? ConsentStatus::Gevraagd : ConsentStatus::NietNodig,
                    'created_by' => $user?->id,
                    'match_score' => $match?->score,
                    ...$attributes,
                ]);

                TransitionOffer::record($offer, OfferActivityType::Created, $user, ['text' => $offer->is_manual ? 'Handmatig geregistreerd' : 'Aanbieding gestart']);

                return $offer;
            });
        } catch (UniqueConstraintViolationException) {
            self::ensureNotOffered($candidate, $assignment);

            throw new DuplicateOfferException("{$candidate->name} is al aangeboden op deze opdracht.");
        }

        if ($match !== null && in_array($match->status, [MatchStatus::Nieuw, MatchStatus::Bekeken], true)) {
            $match->setStatus(MatchStatus::Shortlist);
        }

        return $offer;
    }

    /**
     * @throws DuplicateOfferException
     */
    public static function ensureNotOffered(Candidate $candidate, Assignment $assignment): void
    {
        $existing = Offer::query()
            ->active()
            ->with(['organization', 'assignmentSource', 'assignment'])
            ->where('candidate_id', $candidate->id)
            ->where('dedupe_group_id', $assignment->dedupe_group_id)
            ->first();

        if ($existing === null) {
            return;
        }

        $message = $existing->sent_at !== null
            ? "{$candidate->name} is al aangeboden via {$existing->viaName()} op {$existing->sent_at->format('d-m-Y')}"
            : "{$candidate->name} heeft al een aanbieding in voorbereiding voor deze opdracht (via {$existing->viaName()})";

        throw new DuplicateOfferException($message, $existing);
    }

    /**
     * Waarschuwing (geen blokkade): dezelfde kandidaat is recent aangeboden bij dezelfde eindklant
     * op een vergelijkbare titel (§11.4).
     *
     * @return list<string>
     */
    public function similarOfferWarnings(Assignment $assignment, Candidate $candidate): array
    {
        $warnings = [];

        $recent = Offer::query()
            ->active()
            ->with('assignment')
            ->where('candidate_id', $candidate->id)
            ->where('dedupe_group_id', '!=', $assignment->dedupe_group_id)
            ->where('created_at', '>=', now()->subDays($this->registerSettings->similar_offer_days))
            ->get();

        foreach ($recent as $offer) {
            $sameClient = ($assignment->client_organization_id !== null && $offer->assignment->client_organization_id === $assignment->client_organization_id)
                || (filled($assignment->client_name) && mb_strtolower((string) $offer->assignment->client_name) === mb_strtolower((string) $assignment->client_name));

            if ($sameClient && TitleSimilarity::score($offer->assignment->title, $assignment->title) >= $this->registerSettings->similar_offer_title_similarity) {
                $warnings[] = "{$candidate->name} is op ".($offer->sent_at ?? $offer->created_at)->format('d-m-Y')." al aangeboden bij {$assignment->client_name} op \"{$offer->assignment->title}\".";
            }
        }

        return $warnings;
    }

    /**
     * De route met een contactadres heeft de voorkeur; anders de eerste bron.
     */
    public static function defaultRoute(Assignment $assignment): ?AssignmentSource
    {
        return $assignment->sources()->orderByRaw('contact_email is null')->orderBy('first_seen_at')->first();
    }

    /**
     * Privacy-opties per aanbieding (§12.6).
     *
     * @return array{contact_line: string, show_birth_date: bool, show_city: bool, show_driving_license: bool, show_nationality: bool, show_availability: bool}
     */
    public function defaultCvOptions(Assignment $assignment): array
    {
        $onsite = in_array($assignment->work_mode, [WorkMode::Onsite, WorkMode::Hybride], true);

        return [
            'contact_line' => $this->cvSettings->contact_line,
            'show_birth_date' => $this->cvSettings->show_birth_date,
            'show_city' => $this->cvSettings->show_city,
            'show_driving_license' => match ($this->cvSettings->show_driving_license) {
                'always' => true,
                'never' => false,
                default => $onsite,
            },
            'show_nationality' => filled($assignment->screening['nationaliteit'] ?? null) || ($assignment->screening['veiligheidsonderzoek'] ?? null) !== null,
            'show_availability' => true,
        ];
    }
}
