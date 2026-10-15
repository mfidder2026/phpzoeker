<?php

namespace App\Domain\Matching\Support;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Matching\Enums\Recommendation;
use App\Domain\Matching\Enums\Verdict;
use Illuminate\Support\Str;

/**
 * Controleert de AI-beoordeling deterministisch (§10.2, §10.4):
 * - eisen met een minimum aantal jaren worden vergeleken met de berekende jaren; is dat te
 *   weinig, dan wordt het oordeel `voldoet_niet` (de guard verlaagt alleen, nooit omhoog);
 * - de knock-outs worden opnieuw bepaald uit de eisen zelf, niet uit de lijst van het model;
 * - een gefaalde knock-out of een tekort aan jaren op een eis → aanbeveling `niet_aanbieden`.
 */
class AssessmentGuard
{
    public function __construct(private readonly Technologies $technologies) {}

    /**
     * De jaren die het model meekrijgt: voor elke technologie uit de opdracht en de eisen.
     *
     * @return array<string, array{years: float, since: string|null, level: string|null, source: list<string>}>
     */
    public function yearsTable(Assignment $assignment, ProfileFacts $facts): array
    {
        $names = $assignment->technologies ?? [];

        foreach ($this->requirements($assignment) as $requirement) {
            $parsed = RequirementYears::parse($requirement, $this->knownTechnologies($assignment, $facts), $this->technologies);

            if ($parsed !== null && $parsed['technology'] !== null) {
                $names[] = $parsed['technology'];
            }
        }

        $table = [];

        foreach ($this->technologies->set($names) as $technology) {
            $years = $facts->yearsWith($technology);

            if ($years['years'] > 0) {
                $table[$technology] = $years;
            }
        }

        return $table;
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @return array{assessment: array<string, mixed>, knockout_failed: bool}
     */
    public function apply(array $assessment, Assignment $assignment, ProfileFacts $facts): array
    {
        $verdicts = collect((array) ($assessment['eisen'] ?? []))->filter(fn (mixed $row): bool => is_array($row))->keyBy('id')->all();
        $shortOnYears = false;
        $eisen = [];

        foreach ($this->requirements($assignment) as $requirement) {
            $id = (string) $requirement['id'];
            $row = $this->normalizeRow($verdicts[$id] ?? ['id' => $id]);
            $parsed = RequirementYears::parse($requirement, $this->knownTechnologies($assignment, $facts), $this->technologies);

            if ($parsed !== null) {
                $actual = $parsed['technology'] !== null ? $facts->yearsWith($parsed['technology']) : null;
                $years = $actual['years'] ?? $facts->professionalYears();

                if ($years < $parsed['years']) {
                    $shortOnYears = true;
                    $subject = $parsed['technology'] !== null ? "met {$parsed['technology']}" : 'als professional';
                    $since = $actual['since'] ?? null;

                    if ($row['oordeel'] !== Verdict::VoldoetNiet->value) {
                        $row['gecorrigeerd'] = true;
                    }

                    $row['oordeel'] = Verdict::VoldoetNiet->value;
                    $row['bewijs'] = 'Volgens het profiel '.$this->formatYears($years)." werkervaring {$subject}".($since !== null ? " (sinds {$since})" : '')
                        .'; gevraagd minimaal '.$this->formatYears($parsed['years']).'.';
                    $row['bron_in_profiel'] ??= ($actual['source'][0] ?? 'werkervaring');
                }
            }

            $eisen[] = $row;
        }

        $knockouts = [];

        foreach ($this->requirements($assignment) as $index => $requirement) {
            if (($requirement['knock_out'] ?? false) === true && $eisen[$index]['oordeel'] === Verdict::VoldoetNiet->value) {
                $knockouts[] = (string) $requirement['id'];
            }
        }

        $wishVerdicts = collect((array) ($assessment['wensen'] ?? []))->filter(fn (mixed $row): bool => is_array($row))->keyBy('id')->all();
        $wensen = [];

        foreach (array_filter($assignment->wishes ?? [], fn (array $wish): bool => is_string($wish['id'] ?? null)) as $wish) {
            $wensen[] = $this->normalizeRow($wishVerdicts[$wish['id']] ?? ['id' => $wish['id']]);
        }

        $modelRecommendation = $assessment['aanbeveling_model'] ?? $assessment['aanbeveling'] ?? null;
        $recommendation = Recommendation::tryFrom((string) $modelRecommendation) ?? Recommendation::Twijfel;

        if ($knockouts !== [] || $shortOnYears) {
            $recommendation = Recommendation::NietAanbieden;
        }

        return [
            'assessment' => [
                'eisen' => $eisen,
                'wensen' => $wensen,
                'senioriteit_fit' => in_array($assessment['senioriteit_fit'] ?? null, ['te_laag', 'passend', 'te_hoog'], true) ? $assessment['senioriteit_fit'] : 'passend',
                'sterke_punten' => array_slice(array_values(array_filter((array) ($assessment['sterke_punten'] ?? []), 'is_string')), 0, 3),
                'risicos' => array_slice(array_values(array_filter((array) ($assessment['risicos'] ?? []), 'is_string')), 0, 3),
                'knock_out_gefaald' => $knockouts,
                'aanbeveling' => $recommendation->value,
                'aanbeveling_model' => $modelRecommendation,
                'toelichting' => Str::words((string) ($assessment['toelichting'] ?? ''), 80),
            ],
            'knockout_failed' => $knockouts !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, oordeel: string, bewijs: string|null, bron_in_profiel: string|null, gecorrigeerd: bool}
     */
    private function normalizeRow(array $row): array
    {
        $verdict = Verdict::tryFrom((string) ($row['oordeel'] ?? '')) ?? Verdict::Onbekend;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'oordeel' => $verdict->value,
            'bewijs' => isset($row['bewijs']) && is_string($row['bewijs']) ? Str::words($row['bewijs'], 40) : null,
            'bron_in_profiel' => isset($row['bron_in_profiel']) && is_string($row['bron_in_profiel']) && $row['bron_in_profiel'] !== '' ? $row['bron_in_profiel'] : null,
            'gecorrigeerd' => ($row['gecorrigeerd'] ?? false) === true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requirements(Assignment $assignment): array
    {
        return array_values(array_filter($assignment->requirements ?? [], fn (array $item): bool => is_string($item['id'] ?? null)));
    }

    /**
     * @return list<string>
     */
    private function knownTechnologies(Assignment $assignment, ProfileFacts $facts): array
    {
        return array_values(array_unique([...$this->technologies->set($assignment->technologies ?? []), ...$facts->technologies()]));
    }

    private function formatYears(float $years): string
    {
        return str_replace('.', ',', (string) round($years, 1)).' jaar';
    }
}
