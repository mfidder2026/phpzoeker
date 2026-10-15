<?php

namespace App\Domain\Matching\Support;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\CandidateSearchProfile;

/**
 * Eerste trap van de matching, zonder taalmodel (§10.1): relevantie, uitsluitwoorden en
 * -technologieën, trefwoorden uit het zoekprofiel en overlap in technologie. Alleen wat hier
 * doorkomt, gaat naar de AI-beoordeling.
 */
class Prefilter
{
    public function __construct(private readonly Technologies $technologies) {}

    /**
     * @return array{passed: bool, score: int, reasons: list<string>}
     */
    public function evaluate(Assignment $assignment, ?CandidateSearchProfile $search, ?ProfileFacts $facts): array
    {
        if (! $assignment->is_relevant) {
            return $this->fail('De opdracht is niet relevant voor softwareontwikkeling.');
        }

        if ($facts === null) {
            return $this->fail('De kandidaat heeft nog geen goedgekeurd master-profiel.');
        }

        $title = $assignment->title;

        foreach ((array) ($search->exclude_keywords ?? []) as $keyword) {
            if ($keyword !== '' && $this->technologies->mentionedIn($keyword, $title)) {
                return $this->fail("Uitsluitwoord \"{$keyword}\" staat in de titel.");
            }
        }

        $requested = $this->technologies->set($assignment->technologies ?? []);
        $required = $this->technologies->set(array_map(
            fn (array $requirement): mixed => $requirement['technologie'] ?? null,
            array_filter($assignment->requirements ?? [], 'is_array'),
        ));

        foreach ($this->technologies->set($search->excluded_tech ?? []) as $excluded) {
            if (in_array($excluded, $required, true)) {
                return $this->fail("De opdracht eist {$excluded}; dat staat bij de uitgesloten technologieën van de kandidaat.");
            }
        }

        $reasons = [];
        $text = $title.' '.$assignment->summary.' '.mb_substr((string) $assignment->description_text, 0, 4000);

        $titleHits = array_values(array_filter(
            (array) ($search->include_keywords ?? []),
            fn (string $keyword): bool => $keyword !== '' && $this->technologies->mentionedIn($keyword, $title),
        ));
        $textHits = array_values(array_filter(
            (array) ($search->include_keywords ?? []),
            fn (string $keyword): bool => $keyword !== '' && $this->technologies->mentionedIn($keyword, $text),
        ));

        if ($titleHits !== []) {
            $reasons[] = 'Trefwoorden in de titel: '.implode(', ', $titleHits).'.';
        } elseif ($textHits !== []) {
            $reasons[] = 'Trefwoorden in de tekst: '.implode(', ', array_slice($textHits, 0, 5)).'.';
        }

        $coverage = $this->technologies->coverage($requested, $facts->technologies());
        $preferred = array_values(array_intersect($requested, $this->technologies->set($search->preferred_tech ?? [])));

        if ($coverage !== null) {
            $reasons[] = 'Technologie-overlap '.round($coverage * 100).'%'.($preferred !== [] ? ' (voorkeur: '.implode(', ', $preferred).')' : '').'.';
        }

        $score = (int) round(min(100,
            ($titleHits !== [] ? 40 : ($textHits !== [] ? 20 : 0))
            + 50 * ($coverage ?? 0)
            + min(10, 5 * count($preferred)),
        ));

        $passed = $titleHits !== [] || $preferred !== [] || ($coverage ?? 0) >= 0.25 || ($requested === [] && $textHits !== []);

        if (! $passed) {
            $reasons[] = 'Geen trefwoorden uit het zoekprofiel en te weinig overlap in technologie.';
        }

        return ['passed' => $passed, 'score' => $score, 'reasons' => $reasons];
    }

    /**
     * @return array{passed: bool, score: int, reasons: list<string>}
     */
    private function fail(string $reason): array
    {
        return ['passed' => false, 'score' => 0, 'reasons' => [$reason]];
    }
}
