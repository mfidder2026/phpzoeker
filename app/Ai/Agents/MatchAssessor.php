<?php

namespace App\Ai\Agents;

use App\Ai\AiGateway;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Matching\Data\AssessmentSchema;
use App\Domain\Matching\Support\ProfileFacts;

/**
 * Beoordeelt de eisen en wensen van één opdracht voor één kandidaat (§10.4, Bijlage C).
 * Het profiel gaat zonder naam, contactgegevens en geboortedatum mee (R4); de jaren ervaring
 * zijn vooraf berekend en mogen niet worden opgehoogd.
 */
class MatchAssessor
{
    public const PROMPT = 'match-assessor';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  array<string, array{years: float, since: string|null, level: string|null, source: list<string>}>  $years
     * @return array<string, mixed>
     *
     * @throws AiProviderException
     */
    public function assess(TrackedRun $run, Assignment $assignment, ProfileFacts $facts, array $years, bool $userInitiated = false): array
    {
        $requirements = $this->items($assignment->requirements ?? [], ['id', 'tekst', 'knock_out', 'categorie', 'min_jaren', 'technologie']);
        $wishes = $this->items($assignment->wishes ?? [], ['id', 'tekst', 'gewicht', 'categorie']);

        $opdracht = [
            'titel' => $assignment->title,
            'senioriteit' => $assignment->seniority,
            'samenvatting' => $assignment->summary,
            'technologieen' => $assignment->technologies ?? [],
            'eisen' => $requirements,
            'wensen' => $wishes,
        ];

        $kandidaat = [
            'senioriteit' => $facts->candidate->seniority->value,
            'professionele_werkervaring_jaren' => $facts->professionalYears(),
        ];

        $json = fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $user = "<opdracht>\n{$json($opdracht)}\n</opdracht>\n\n"
            ."<kandidaat>\n{$json($kandidaat)}\n</kandidaat>\n\n"
            ."<berekende_jaren>\n{$json(array_map(fn (array $row): array => array_filter(['jaren_maximaal' => $row['years'], 'sinds' => $row['since'], 'niveau' => $row['level']], fn (mixed $value): bool => $value !== null), $years))}\n</berekende_jaren>\n\n"
            ."<profiel>\n{$json($facts->forPrompt())}\n</profiel>";

        return $this->gateway->structured(
            run: $run,
            task: AiTask::Match,
            system: $this->prompts->get(self::PROMPT),
            user: $user,
            schema: AssessmentSchema::jsonSchema(),
            validator: fn (array $data): ?string => AssessmentSchema::validate(
                $data,
                array_map(fn (array $item): string => (string) $item['id'], $requirements),
                array_map(fn (array $item): string => (string) $item['id'], $wishes),
            ),
            userInitiated: $userInitiated,
        );
    }

    /**
     * @param  array<mixed>  $items
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function items(array $items, array $keys): array
    {
        $result = [];

        foreach ($items as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $result[] = array_intersect_key($item, array_flip($keys));
            }
        }

        return $result;
    }
}
