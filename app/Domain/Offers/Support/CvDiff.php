<?php

namespace App\Domain\Offers\Support;

/**
 * Verschil tussen master-profiel en getailord CV per sectie (FR-C3), voor de
 * verschilweergave in de wizard.
 */
final class CvDiff
{
    /**
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $tailored
     * @return array<string, mixed>
     */
    public static function summarize(array $master, array $tailored): array
    {
        $items = fn (array $profile): array => collect((array) ($profile['kernexpertise'] ?? []))
            ->flatMap(fn (mixed $block): array => array_map(fn (mixed $item): string => (string) ($item['naam'] ?? ''), (array) ($block['items'] ?? [])))
            ->filter()->values()->all();

        $jobs = [];
        $masterJobs = collect((array) ($master['werkervaring'] ?? []))->keyBy('id');

        foreach ((array) ($tailored['werkervaring'] ?? []) as $job) {
            $original = (array) $masterJobs->get($job['id'] ?? '', []);
            $before = array_values((array) ($original['bullets'] ?? []));
            $after = array_values((array) ($job['bullets'] ?? []));

            $jobs[] = [
                'id' => $job['id'] ?? null,
                'titel' => ($job['functie'] ?? '').' — '.($job['organisatie'] ?? ''),
                'bullets_voor' => $before,
                'bullets_na' => $after,
                'gewijzigd' => $before !== $after,
            ];
        }

        $masterProjects = array_map(fn (mixed $project): string => (string) ($project['naam'] ?? ''), (array) ($master['projecten'] ?? []));
        $tailoredProjects = array_map(fn (mixed $project): string => (string) ($project['naam'] ?? ''), (array) ($tailored['projecten'] ?? []));

        return [
            'profieltekst' => [
                'voor' => (string) ($master['profieltekst'] ?? ''),
                'na' => (string) ($tailored['profieltekst'] ?? ''),
                'gewijzigd' => trim((string) ($master['profieltekst'] ?? '')) !== trim((string) ($tailored['profieltekst'] ?? '')),
            ],
            'cv_titel' => ['voor' => (string) ($master['cv_titel'] ?? ''), 'na' => (string) ($tailored['cv_titel'] ?? '')],
            'kernexpertise' => [
                'weggelaten' => array_values(array_diff($items($master), $items($tailored))),
                'volgorde' => $items($tailored),
            ],
            'werkervaring' => $jobs,
            'werkervaring_weggelaten' => array_values(array_diff($masterJobs->keys()->all(), array_column((array) ($tailored['werkervaring'] ?? []), 'id'))),
            'projecten' => ['gekozen' => $tailoredProjects, 'weggelaten' => array_values(array_diff($masterProjects, $tailoredProjects))],
            'aansluiting' => count((array) ($tailored['aansluiting_op_opdracht'] ?? [])),
        ];
    }
}
