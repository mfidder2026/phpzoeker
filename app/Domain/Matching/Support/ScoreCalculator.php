<?php

namespace App\Domain\Matching\Support;

use App\Domain\Assignments\Enums\WorkMode;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Enums\Seniority;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Enums\Verdict;
use App\Settings\MatchingSettings;
use Carbon\CarbonImmutable;

/**
 * Score van 0 tot 100 (§10.3): `score = round(Σ gewicht × component)`. De opbouw komt in
 * `score_breakdown` en wordt als staafjes getoond. Zonder AI-beoordeling tellen alle eisen en
 * wensen als "onbekend".
 */
class ScoreCalculator
{
    /**
     * @var array<string, string>
     */
    public const COMPONENTS = [
        'requirements' => 'Eisen',
        'wishes' => 'Wensen',
        'technology' => 'Technologie',
        'seniority' => 'Senioriteit',
        'rate' => 'Tarief',
        'location' => 'Locatie/reistijd',
        'hours' => 'Uren/start/duur',
    ];

    public const WARNING_RATE_ABOVE_MAX = 'Tarief boven maximum';

    public function __construct(
        private readonly MatchingSettings $settings,
        private readonly Technologies $technologies,
    ) {}

    /**
     * @param  array<string, mixed>|null  $assessment  Bijlage C (na `AssessmentGuard`)
     * @param  list<string>  $profileTechnologies  genormaliseerd
     * @return array{score: int, breakdown: array<string, array{label: string, weight: int, value: float, points: float, detail: string}>, warnings: list<string>}
     */
    public function calculate(
        Assignment $assignment,
        Candidate $candidate,
        ?array $assessment,
        array $profileTechnologies,
        ?int $travelMinutes,
        bool $partialAbsence,
    ): array {
        $warnings = [];
        $components = [
            'requirements' => $this->requirements($assignment, $assessment),
            'wishes' => $this->wishes($assignment, $assessment),
            'technology' => $this->technology($assignment, $profileTechnologies),
            'seniority' => $this->seniority($assignment, $candidate, $assessment),
            'rate' => $this->rate($assignment, $candidate, $warnings),
            'location' => $this->location($assignment, $travelMinutes),
            'hours' => $this->hours($assignment, $candidate, $partialAbsence),
        ];

        $breakdown = [];
        $total = 0.0;

        foreach ($components as $key => [$value, $detail]) {
            $weight = (int) ($this->settings->weights[$key] ?? 0);
            $value = max(0.0, min(1.0, $value));
            $points = round($weight * $value, 1);
            $total += $weight * $value;

            $breakdown[$key] = [
                'label' => self::COMPONENTS[$key],
                'weight' => $weight,
                'value' => round($value, 2),
                'points' => $points,
                'detail' => $detail,
            ];
        }

        return ['score' => (int) round($total), 'breakdown' => $breakdown, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>|null  $assessment
     * @return array{0: float, 1: string}
     */
    private function requirements(Assignment $assignment, ?array $assessment): array
    {
        $items = array_values(array_filter($assignment->requirements ?? [], 'is_array'));

        if ($items === []) {
            return [0.5, 'Geen eisen genoemd (neutraal).'];
        }

        $verdicts = $this->verdicts($assessment['eisen'] ?? []);
        $values = array_map(fn (array $item): float => ($verdicts[$item['id'] ?? ''] ?? Verdict::Onbekend)->value(), $items);
        $met = count(array_filter($values, fn (float $value): bool => $value >= 1.0));

        return [array_sum($values) / count($values), $assessment === null
            ? count($items).' eisen, nog niet beoordeeld (telt als onbekend).'
            : "{$met} van ".count($items).' eisen volledig.'];
    }

    /**
     * @param  array<string, mixed>|null  $assessment
     * @return array{0: float, 1: string}
     */
    private function wishes(Assignment $assignment, ?array $assessment): array
    {
        $items = array_values(array_filter($assignment->wishes ?? [], 'is_array'));

        if ($items === []) {
            return [0.5, 'Geen wensen genoemd (neutraal).'];
        }

        $verdicts = $this->verdicts($assessment['wensen'] ?? []);
        $weighted = 0.0;
        $weights = 0.0;

        foreach ($items as $item) {
            $weight = is_numeric($item['gewicht'] ?? null) && (float) $item['gewicht'] > 0 ? (float) $item['gewicht'] : 1.0;
            $weighted += $weight * ($verdicts[$item['id'] ?? ''] ?? Verdict::Onbekend)->value();
            $weights += $weight;
        }

        return [$weighted / $weights, count($items).' wensen'.($assessment === null ? ', nog niet beoordeeld.' : ', gewogen gemiddelde.')];
    }

    /**
     * @param  list<string>  $profileTechnologies
     * @return array{0: float, 1: string}
     */
    private function technology(Assignment $assignment, array $profileTechnologies): array
    {
        $requested = $this->technologies->set($assignment->technologies ?? []);
        $coverage = $this->technologies->coverage($requested, $profileTechnologies);

        if ($coverage === null) {
            return [0.5, 'Geen technologieën genoemd (neutraal).'];
        }

        $matched = array_values(array_intersect($requested, $profileTechnologies));
        $missing = array_values(array_diff($requested, $profileTechnologies));

        return [$coverage, count($matched).' van '.count($requested).' technologieën in het profiel'
            .($missing !== [] ? '; ontbreekt: '.implode(', ', array_slice($missing, 0, 6)) : '').'.'];
    }

    /**
     * @param  array<string, mixed>|null  $assessment
     * @return array{0: float, 1: string}
     */
    private function seniority(Assignment $assignment, Candidate $candidate, ?array $assessment): array
    {
        $wanted = Seniority::tryFrom($assignment->seniority);

        if ($wanted !== null) {
            $difference = abs($wanted->rank() - $candidate->seniority->rank());

            return [match ($difference) {
                0 => 1.0,
                1 => 0.4,
                default => 0.0,
            }, 'Gevraagd '.$wanted->getLabel().'; kandidaat '.$candidate->seniority->getLabel().'.'];
        }

        return match ($assessment['senioriteit_fit'] ?? null) {
            'passend' => [1.0, 'Niveau niet genoemd; volgens de beoordeling passend.'],
            'te_laag' => [0.4, 'Niveau niet genoemd; volgens de beoordeling te laag.'],
            'te_hoog' => [0.4, 'Niveau niet genoemd; volgens de beoordeling te hoog.'],
            default => [0.6, 'Niveau niet genoemd.'],
        };
    }

    /**
     * @param  list<string>  $warnings
     * @return array{0: float, 1: string}
     */
    private function rate(Assignment $assignment, Candidate $candidate, array &$warnings): array
    {
        $rate = (float) $candidate->hourly_rate;
        $euro = fn (float $amount): string => '€ '.number_format($amount, 2, ',', '.');

        if ($assignment->max_rate === null) {
            return [0.6, 'Maximumtarief onbekend; kandidaat '.$euro($rate).'.'];
        }

        $max = (float) $assignment->max_rate;
        $detail = 'Maximum '.$euro($max).($assignment->rate_includes_margin ? ' (incl. marge)' : '').'; kandidaat '.$euro($rate).'.';

        if ($max >= $rate) {
            return [1.0, $detail];
        }

        if ($max >= $rate * 0.9) {
            $warnings[] = 'Tarief tot 10% boven maximum';

            return [0.5, $detail];
        }

        $warnings[] = self::WARNING_RATE_ABOVE_MAX;

        return [0.0, $detail];
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function location(Assignment $assignment, ?int $travelMinutes): array
    {
        if ($assignment->work_mode === WorkMode::Remote) {
            return [1.0, 'Remote.'];
        }

        if ($travelMinutes === null) {
            return [0.5, 'Reistijd onbekend.'];
        }

        $value = match (true) {
            $travelMinutes <= 30 => 1.0,
            $travelMinutes >= 120 => 0.2,
            default => 1.0 - 0.8 * (($travelMinutes - 30) / 90),
        };

        return [$value, "± {$travelMinutes} min reistijd".($assignment->work_mode === WorkMode::Hybride ? ' (hybride)' : '').'.'];
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function hours(Assignment $assignment, Candidate $candidate, bool $partialAbsence): array
    {
        $parts = [];
        $hoursValue = 1.0;

        if ($assignment->hours_max !== null && $assignment->hours_max < $candidate->hours_per_week) {
            $hoursValue = $assignment->hours_max / max(1, $candidate->hours_per_week);
            $parts[] = "{$assignment->hours_max} van {$candidate->hours_per_week} uur";
        } elseif ($assignment->hours_max !== null) {
            $parts[] = "{$assignment->hours_max} uur";
        }

        $startValue = 1.0;

        if (! $assignment->start_asap && $assignment->start_date !== null) {
            $days = max(0, (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($assignment->start_date->toDateString()), false));
            $startValue = max(0.5, 1.0 - 0.5 * min(1, $days / 90));
            $parts[] = 'start '.$assignment->start_date->format('d-m-Y');
        } else {
            $parts[] = 'start per direct';
        }

        $value = ($hoursValue + $startValue) / 2;

        if ($partialAbsence) {
            $value -= 0.2;
            $parts[] = 'gedeeltelijke afwezigheid (−0,2)';
        }

        return [$value, ucfirst(implode(', ', $parts)).'.'];
    }

    /**
     * @return array<string, Verdict>
     */
    private function verdicts(mixed $items): array
    {
        $verdicts = [];

        foreach ((array) $items as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $verdicts[$item['id']] = Verdict::tryFrom((string) ($item['oordeel'] ?? '')) ?? Verdict::Onbekend;
            }
        }

        return $verdicts;
    }
}
