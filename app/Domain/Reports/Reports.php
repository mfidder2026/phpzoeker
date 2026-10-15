<?php

namespace App\Domain\Reports;

use App\Ai\Runs\AiRun;
use App\Domain\Assignments\Models\AssignmentSource;
use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Models\Offer;
use App\Settings\MatchingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rapportages (FR-R1, FR-M7, §14): opdrachten per bron per week, matches per kandidaat, de
 * funnel, doorlooptijden, AI-kosten en gemiste kansen. Berekend in PHP, zodat het op SQLite
 * en PostgreSQL hetzelfde werkt; de aantallen zijn klein (§20).
 */
class Reports
{
    public function __construct(private readonly MatchingSettings $matching) {}

    public static function since(int $days): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays($days)->startOfDay();
    }

    /**
     * Nieuwe opdrachten per bron per week.
     *
     * @return array{weeks: list<string>, sources: array<string, array<string, int>>, max: int}
     */
    public function assignmentsPerSource(CarbonImmutable $since): array
    {
        $rows = AssignmentSource::query()
            ->with('source:id,name')
            ->where('first_seen_at', '>=', $since)
            ->get(['id', 'source_id', 'assignment_id', 'first_seen_at'])
            ->unique('assignment_id');

        $weeks = [];

        for ($week = $since->startOfWeek(); $week->lessThanOrEqualTo(CarbonImmutable::now()); $week = $week->addWeek()) {
            $weeks[] = $week->format('o-\WW');
        }

        $sources = [];

        foreach ($rows as $row) {
            $name = $row->source->name ?? 'Onbekend';
            $week = CarbonImmutable::parse($row->first_seen_at)->format('o-\WW');
            $sources[$name] ??= array_fill_keys($weeks, 0);

            if (isset($sources[$name][$week])) {
                $sources[$name][$week]++;
            }
        }

        $max = 0;

        foreach ($weeks as $week) {
            $max = max($max, array_sum(array_map(fn (array $counts): int => $counts[$week], $sources)));
        }

        ksort($sources);

        return ['weeks' => $weeks, 'sources' => $sources, 'max' => $max];
    }

    /**
     * Matches per kandidaat per label, met feedback.
     *
     * @return list<array{candidate: string, sterk: int, mogelijk: int, zwak: int, afgevallen: int, up: int, down: int, offered: int}>
     */
    public function matchesPerCandidate(CarbonImmutable $since): array
    {
        return array_values(CandidateMatch::query()
            ->with('candidate:id,name')
            ->where('created_at', '>=', $since)
            ->get(['id', 'candidate_id', 'label', 'feedback', 'status'])
            ->groupBy(fn (CandidateMatch $match): string => $match->candidate->name)
            ->map(fn (Collection $matches, string $name): array => [
                'candidate' => $name,
                'sterk' => $matches->where('label', MatchLabel::Sterk)->count(),
                'mogelijk' => $matches->where('label', MatchLabel::Mogelijk)->count(),
                'zwak' => $matches->where('label', MatchLabel::Zwak)->count(),
                'afgevallen' => $matches->where('label', MatchLabel::Afgevallen)->count(),
                'up' => $matches->where('feedback', CandidateMatch::FEEDBACK_UP)->count(),
                'down' => $matches->where('feedback', CandidateMatch::FEEDBACK_DOWN)->count(),
                'offered' => $matches->where('status', MatchStatus::Aangeboden)->count(),
            ])
            ->sortKeys()
            ->all());
    }

    /**
     * Funnel: kansrijke match → shortlist → aangeboden → reactie → intake → geplaatst.
     *
     * @return list<array{label: string, count: int}>
     */
    public function funnel(CarbonImmutable $since): array
    {
        $matches = CandidateMatch::query()->where('created_at', '>=', $since)->get(['id', 'label', 'status', 'hard_filter_passed']);
        $offers = Offer::query()->where('created_at', '>=', $since)->get(['id', 'status', 'sent_at', 'response_received_at', 'intake_at']);

        return [
            ['label' => 'Sterke of mogelijke match', 'count' => $matches->filter(fn (CandidateMatch $match): bool => $match->hard_filter_passed && $match->label->isPromising())->count()],
            ['label' => 'Shortlist', 'count' => $matches->whereIn('status', [MatchStatus::Shortlist, MatchStatus::Aangeboden])->count()],
            ['label' => 'Aangeboden', 'count' => $offers->whereNotNull('sent_at')->count()],
            ['label' => 'Reactie', 'count' => $offers->whereNotNull('response_received_at')->count()],
            ['label' => 'Intake', 'count' => $offers->filter(fn (Offer $offer): bool => $offer->intake_at !== null || in_array($offer->status, [OfferStatus::IntakeGepland, OfferStatus::Geplaatst], true))->count()],
            ['label' => 'Geplaatst', 'count' => $offers->where('status', OfferStatus::Geplaatst)->count()],
        ];
    }

    /**
     * Doorlooptijden: van publicatie tot aanbieding, reactietijd en tijd tot intake.
     *
     * @return array{publication_to_offer_hours: float|null, response_workdays: float|null, sent_to_intake_days: float|null, samples: int}
     */
    public function leadTimes(CarbonImmutable $since): array
    {
        $offers = Offer::query()->with('assignment:id,first_seen_at')->where('sent_at', '>=', $since)->get();

        $toOffer = $offers->filter(fn (Offer $offer): bool => $offer->assignment->first_seen_at !== null && ! $offer->is_manual)
            ->map(fn (Offer $offer): float => max(0, (float) $offer->assignment->first_seen_at?->diffInMinutes($offer->sent_at) / 60));
        $toIntake = $offers->filter(fn (Offer $offer): bool => $offer->intake_at !== null)
            ->map(fn (Offer $offer): float => max(0, (float) $offer->sent_at?->diffInHours($offer->intake_at) / 24));
        $response = $offers->whereNotNull('response_time_workdays')->map(fn (Offer $offer): float => (float) $offer->response_time_workdays);

        return [
            'publication_to_offer_hours' => $toOffer->isEmpty() ? null : round((float) $toOffer->avg(), 1),
            'response_workdays' => $response->isEmpty() ? null : round((float) $response->avg(), 1),
            'sent_to_intake_days' => $toIntake->isEmpty() ? null : round((float) $toIntake->avg(), 1),
            'samples' => $offers->count(),
        ];
    }

    /**
     * AI-kosten per dag en per agent.
     *
     * @return array{days: array<string, float>, agents: list<array{agent: string, runs: int, failed: int, tokens: int, cost: float}>, total: float, max_day: float}
     */
    public function aiCosts(CarbonImmutable $since): array
    {
        $runs = AiRun::query()->where('queued_at', '>=', $since)->get(['id', 'agent', 'status', 'input_tokens', 'output_tokens', 'cost_eur', 'queued_at']);
        $days = [];

        for ($day = $since; $day->lessThanOrEqualTo(CarbonImmutable::now()); $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = 0.0;
        }

        foreach ($runs as $run) {
            $key = CarbonImmutable::parse($run->queued_at)->format('Y-m-d');

            if (isset($days[$key])) {
                $days[$key] += (float) $run->cost_eur;
            }
        }

        $agents = $runs->groupBy(fn (AiRun $run): string => class_basename((string) $run->agent))
            ->map(fn (Collection $group, string $agent): array => [
                'agent' => $agent,
                'runs' => $group->count(),
                'failed' => $group->filter(fn (AiRun $run): bool => $run->status->value === 'failed')->count(),
                'tokens' => (int) $group->sum(fn (AiRun $run): int => (int) $run->input_tokens + (int) $run->output_tokens),
                'cost' => round((float) $group->sum('cost_eur'), 4),
            ])
            ->sortByDesc('runs')
            ->all();
        $agents = array_values($agents);

        return ['days' => $days, 'agents' => $agents, 'total' => round(array_sum($days), 4), 'max_day' => $days === [] ? 0.0 : max($days)];
    }

    /**
     * Gemiste kansen (FR-M7): aanbiedingen die het team deed terwijl de app de match laag scoorde
     * (of geen match had). Input om de matching bij te sturen.
     *
     * @return list<array{offer: Offer, score: int|null, label: string}>
     */
    public function missedChances(CarbonImmutable $since): array
    {
        return array_values(Offer::query()
            ->with(['candidate', 'assignment', 'match', 'organization', 'assignmentSource'])
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $since)
            ->get()
            ->filter(fn (Offer $offer): bool => $offer->match === null
                || ! $offer->match->label->isPromising()
                || $offer->match->score < $this->matching->possible_threshold)
            ->map(fn (Offer $offer): array => [
                'offer' => $offer,
                'score' => $offer->match?->score,
                'label' => $offer->match?->label->getLabel() ?? 'Geen match berekend',
            ])
            ->all());
    }
}
