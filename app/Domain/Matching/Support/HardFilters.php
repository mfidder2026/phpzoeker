<?php

namespace App\Domain\Matching\Support;

use App\Domain\Assignments\Enums\AssignmentContractType;
use App\Domain\Assignments\Enums\WorkMode;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Candidates\Models\CandidateAbsence;
use App\Settings\MatchingSettings;
use Carbon\CarbonImmutable;

/**
 * Harde filters (§10.2). Een `fail` zet `hard_filter_passed` op false; de match blijft bewaard
 * met de reden. Leeftijd en tarief zijn bewust géén filter (R4, §10.3). De knock-out-eisen
 * volgen uit de AI-beoordeling en staan in `AssessmentGuard`.
 */
class HardFilters
{
    public const OK = 'ok';

    public const FAIL = 'fail';

    public const WARNING = 'warning';

    public const NOT_APPLICABLE = 'na';

    public function __construct(private readonly MatchingSettings $settings) {}

    /**
     * @return list<array{key: string, label: string, outcome: string, detail: string}>
     */
    public function evaluate(Assignment $assignment, Candidate $candidate, ?int $travelMinutes): array
    {
        $search = $candidate->searchProfile;
        [$start, $end] = self::period($assignment);

        return [
            $this->status($candidate),
            $this->availability($candidate, $assignment),
            $this->hours($assignment, $candidate),
            $this->tooFewHours($assignment, (int) ($search->min_hours ?? 24)),
            $this->contract($assignment, (array) ($search->accepted_contract_types ?? [])),
            $this->deadline($assignment),
            $this->absence($candidate, $start, $end),
            $this->travel($assignment, $search?->max_travel_minutes, $travelMinutes),
        ];
    }

    /**
     * @param  list<array{key: string, label: string, outcome: string, detail: string}>  $results
     */
    public static function passed(array $results): bool
    {
        return collect($results)->doesntContain(fn (array $result): bool => $result['outcome'] === self::FAIL);
    }

    /**
     * Looptijd van de opdracht: start (of vandaag bij "z.s.m."/onbekend) tot einde (of start +
     * duur). Zonder einde en duur is het einde onbekend.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable|null}
     */
    public static function period(Assignment $assignment): array
    {
        $start = $assignment->start_date !== null ? CarbonImmutable::parse($assignment->start_date->toDateString()) : CarbonImmutable::today();

        if ($assignment->end_date !== null) {
            return [$start, CarbonImmutable::parse($assignment->end_date->toDateString())];
        }

        if ($assignment->duration_months !== null && $assignment->duration_months > 0) {
            return [$start, $start->addMonthsNoOverflow($assignment->duration_months)->subDay()];
        }

        return [$start, null];
    }

    /**
     * Afwezigheden die (deels) binnen de looptijd vallen.
     *
     * @return array{full: CandidateAbsence|null, partial: list<CandidateAbsence>}
     */
    public static function absenceOverlap(Candidate $candidate, CarbonImmutable $start, ?CarbonImmutable $end): array
    {
        $full = null;
        $partial = [];

        foreach ($candidate->absences as $absence) {
            $absenceStart = CarbonImmutable::parse($absence->starts_on->toDateString());
            $absenceEnd = CarbonImmutable::parse($absence->ends_on->toDateString());

            if ($end !== null && $start->greaterThanOrEqualTo($absenceStart) && $end->lessThanOrEqualTo($absenceEnd)) {
                $full = $absence;

                continue;
            }

            $overlaps = $absenceEnd->greaterThanOrEqualTo($start) && ($end === null || $absenceStart->lessThanOrEqualTo($end));

            if ($overlaps) {
                $partial[] = $absence;
            }
        }

        return ['full' => $full, 'partial' => $partial];
    }

    /**
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function status(Candidate $candidate): array
    {
        return $this->result('status', 'Kandidaat actief', $candidate->status->isMatchable(),
            $candidate->status->isMatchable() ? 'Status: '.$candidate->status->getLabel().'.' : 'Status is '.$candidate->status->getLabel().'.');
    }

    /**
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function availability(Candidate $candidate, Assignment $assignment): array
    {
        if ($candidate->available_from === null || $candidate->available_from->isPast()) {
            return $this->result('beschikbaarheid', 'Beschikbaarheid', true, 'Per direct beschikbaar.');
        }

        $start = $assignment->start_date !== null ? CarbonImmutable::parse($assignment->start_date->toDateString()) : CarbonImmutable::today();
        $latest = $start->addDays($this->settings->start_date_tolerance_days);
        $available = CarbonImmutable::parse($candidate->available_from->toDateString());

        return $this->result('beschikbaarheid', 'Beschikbaarheid', $available->lessThanOrEqualTo($latest),
            'Beschikbaar vanaf '.$available->format('d-m-Y').'; start opdracht '.$start->format('d-m-Y')." (tolerantie {$this->settings->start_date_tolerance_days} dagen).");
    }

    /**
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function hours(Assignment $assignment, Candidate $candidate): array
    {
        if ($assignment->hours_min === null) {
            return $this->notApplicable('uren', 'Uren', 'Aantal uren onbekend.');
        }

        return $this->result('uren', 'Uren', $assignment->hours_min <= $candidate->hours_per_week,
            "Opdracht minimaal {$assignment->hours_min} uur; kandidaat {$candidate->hours_per_week} uur.");
    }

    /**
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function tooFewHours(Assignment $assignment, int $minHours): array
    {
        if ($assignment->hours_max === null) {
            return $this->notApplicable('te_weinig_uren', 'Te weinig uren', 'Aantal uren onbekend.');
        }

        return $this->result('te_weinig_uren', 'Te weinig uren', $assignment->hours_max >= $minHours,
            "Opdracht maximaal {$assignment->hours_max} uur; zoekprofiel minimaal {$minHours} uur.");
    }

    /**
     * @param  array<mixed>  $accepted
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function contract(Assignment $assignment, array $accepted): array
    {
        $accepted = $accepted === [] ? ['detachering', 'zzp', 'beide'] : array_values(array_filter($accepted, 'is_string'));
        $acceptsDetachering = array_intersect($accepted, ['detachering', 'beide']) !== [];
        $acceptsZzp = array_intersect($accepted, ['zzp', 'beide']) !== [];
        $type = $assignment->contract_type;

        $passed = match ($type) {
            AssignmentContractType::Detachering => $acceptsDetachering || ($acceptsZzp && $assignment->zzp_allowed === true),
            AssignmentContractType::Zzp => $acceptsZzp,
            AssignmentContractType::Beide => $acceptsDetachering || $acceptsZzp,
            AssignmentContractType::Loondienst => false,
            AssignmentContractType::Onbekend => ! ($assignment->zzp_allowed === false && ! $acceptsDetachering),
        };

        if ($type === AssignmentContractType::Onbekend && $passed) {
            return $this->notApplicable('contractvorm', 'Contractvorm', 'Contractvorm onbekend.');
        }

        return $this->result('contractvorm', 'Contractvorm', $passed,
            'Opdracht: '.$type->getLabel().($assignment->zzp_allowed === false ? ' (geen zzp)' : '').'; kandidaat accepteert '.implode(', ', $accepted).'.');
    }

    /**
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function deadline(Assignment $assignment): array
    {
        if ($assignment->deadline_at === null) {
            return $this->notApplicable('deadline', 'Deadline', 'Geen deadline bekend.');
        }

        return $this->result('deadline', 'Deadline', $assignment->deadline_at->isFuture(),
            'Deadline '.$assignment->deadline_at->format('d-m-Y H:i').'.');
    }

    /**
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function absence(Candidate $candidate, CarbonImmutable $start, ?CarbonImmutable $end): array
    {
        $overlap = self::absenceOverlap($candidate, $start, $end);
        $describe = fn (CandidateAbsence $absence): string => $absence->type->getLabel().' '.$absence->starts_on->format('d-m-Y').' t/m '.$absence->ends_on->format('d-m-Y');

        if ($overlap['full'] !== null) {
            return $this->result('afwezigheid', 'Afwezigheid', false, 'De opdracht valt volledig binnen: '.$describe($overlap['full']).'.');
        }

        if ($overlap['partial'] !== []) {
            return [
                'key' => 'afwezigheid',
                'label' => 'Afwezigheid',
                'outcome' => self::WARNING,
                'detail' => 'Gedeeltelijke overlap: '.implode('; ', array_map($describe, $overlap['partial'])).'.',
            ];
        }

        return $this->result('afwezigheid', 'Afwezigheid', true, 'Geen afwezigheid tijdens de opdracht.');
    }

    /**
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function travel(Assignment $assignment, ?int $maxMinutes, ?int $travelMinutes): array
    {
        if ($maxMinutes === null) {
            return $this->notApplicable('reistijd', 'Max. reistijd', 'Geen maximum ingesteld.');
        }

        if ($assignment->work_mode === WorkMode::Remote) {
            return $this->result('reistijd', 'Max. reistijd', true, 'Remote opdracht.');
        }

        if ($travelMinutes === null) {
            return $this->notApplicable('reistijd', 'Max. reistijd', 'Reistijd onbekend.');
        }

        return $this->result('reistijd', 'Max. reistijd', $travelMinutes <= $maxMinutes, "± {$travelMinutes} min; maximum {$maxMinutes} min.");
    }

    /**
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function result(string $key, string $label, bool $passed, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'outcome' => $passed ? self::OK : self::FAIL, 'detail' => $detail];
    }

    /**
     * @return array{key: string, label: string, outcome: string, detail: string}
     */
    private function notApplicable(string $key, string $label, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'outcome' => self::NOT_APPLICABLE, 'detail' => $detail];
    }
}
