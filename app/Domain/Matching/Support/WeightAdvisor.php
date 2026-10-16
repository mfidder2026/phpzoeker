<?php

namespace App\Domain\Matching\Support;

use App\Domain\Matching\Models\CandidateMatch;
use App\Settings\MatchingSettings;

/**
 * Stelt nieuwe gewichten voor op basis van de feedback (FR-M6, §22 fase 5): een component die
 * goed onderscheidt tussen 👍 en 👎 (hogere waarde bij 👍 dan bij 👎) krijgt meer gewicht, een
 * component die niet of omgekeerd onderscheidt minder. Alleen een voorstel; een beheerder neemt
 * het over.
 */
class WeightAdvisor
{
    /** Minimaal aantal beoordelingen per kant (👍 en 👎) voor een voorstel. */
    public const MIN_PER_SIDE = 3;

    /** Hoe sterk het verschil doorwerkt (0 = niets, 1 = volledig). */
    public const STRENGTH = 0.6;

    public function __construct(private readonly MatchingSettings $settings) {}

    /**
     * @return array{ready: bool, up: int, down: int, components: array<string, array{label: string, current: int, suggested: int, up_mean: float|null, down_mean: float|null}>, agreement: float|null}
     */
    public function advise(): array
    {
        $matches = CandidateMatch::query()->whereNotNull('feedback')->whereNotNull('score_breakdown')->get(['id', 'feedback', 'score_breakdown', 'label']);
        $up = $matches->where('feedback', CandidateMatch::FEEDBACK_UP);
        $down = $matches->where('feedback', CandidateMatch::FEEDBACK_DOWN);
        $ready = $up->count() >= self::MIN_PER_SIDE && $down->count() >= self::MIN_PER_SIDE;
        $weights = $this->settings->weights;
        $raw = [];
        $components = [];

        foreach (ScoreCalculator::COMPONENTS as $key => $label) {
            $mean = fn ($group): ?float => $group->isEmpty() ? null : round((float) $group->avg(fn (CandidateMatch $match): float => (float) ($match->score_breakdown[$key]['value'] ?? 0)), 2);
            $upMean = $mean($up);
            $downMean = $mean($down);
            $separation = $upMean !== null && $downMean !== null ? $upMean - $downMean : 0.0;
            $raw[$key] = max(0.0, (float) ($weights[$key] ?? 0) * (1 + self::STRENGTH * $separation * 2));

            $components[$key] = ['label' => $label, 'current' => (int) ($weights[$key] ?? 0), 'suggested' => (int) ($weights[$key] ?? 0), 'up_mean' => $upMean, 'down_mean' => $downMean];
        }

        if ($ready) {
            $suggested = self::normalize($raw);

            foreach ($components as $key => $component) {
                $components[$key] = [...$component, 'suggested' => $suggested[$key] ?? $component['current']];
            }
        }

        // Hoe vaak het label overeenkomt met de feedback: sterk/mogelijk bij 👍, zwak/afgevallen bij 👎.
        $agreement = $matches->isEmpty() ? null : round($matches->filter(fn (CandidateMatch $match): bool => $match->label->isPromising() === ($match->feedback === CandidateMatch::FEEDBACK_UP))->count() / $matches->count() * 100);

        return ['ready' => $ready, 'up' => $up->count(), 'down' => $down->count(), 'components' => $components, 'agreement' => $agreement];
    }

    /**
     * @return array<string, int>
     */
    public function suggestedWeights(): array
    {
        return array_map(fn (array $component): int => $component['suggested'], $this->advise()['components']);
    }

    /**
     * Afronden naar hele getallen die samen precies 100 zijn (grootste-restmethode).
     *
     * @param  array<string, float>  $raw
     * @return array<string, int>
     */
    public static function normalize(array $raw): array
    {
        $total = array_sum($raw);

        if ($total <= 0) {
            return array_map(fn (): int => 0, $raw);
        }

        $scaled = array_map(fn (float $value): float => $value / $total * 100, $raw);
        $result = array_map(fn (float $value): int => (int) floor($value), $scaled);
        $remainders = [];

        foreach ($scaled as $key => $value) {
            $remainders[$key] = $value - floor($value);
        }

        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, 100 - array_sum($result)) as $key) {
            $result[$key]++;
        }

        return $result;
    }
}
