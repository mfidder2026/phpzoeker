<?php

namespace App\Ai\Agents;

use App\Ai\AiGateway;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Domain\Candidates\Data\CandidateProfileSchema;

/**
 * Zet de tekst van een bestaand CV om naar een master-profiel (Bijlage D, §12.1).
 * De uitkomst is altijd een concept dat een mens reviewt en goedkeurt.
 */
class CvImporter
{
    public const PROMPT = 'cv-importer';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AiProviderException
     */
    public function import(TrackedRun $run, string $scrubbedCvText): array
    {
        $data = $this->gateway->structured(
            run: $run,
            task: AiTask::Cv,
            system: $this->prompts->get(self::PROMPT),
            user: "<cv>\n{$scrubbedCvText}\n</cv>",
            schema: CandidateProfileSchema::jsonSchema(),
            validator: function (array $data): ?string {
                $errors = CandidateProfileSchema::validate(self::normalize($data));

                return $errors === [] ? null : implode(' ', array_slice($errors, 0, 8));
            },
        );

        return self::normalize($data);
    }

    /**
     * Vult ontbrekende optionele lijsten aan, zodat de validatie en de editor een vaste vorm hebben.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $data = [...CandidateProfileSchema::empty(), ...$data];

        foreach (['kernexpertise', 'werkervaring', 'projecten', 'opleidingen', 'certificaten', 'eerdere_ervaring', 'talen', 'overige_vaardigheden'] as $list) {
            $data[$list] = is_array($data[$list]) ? array_values($data[$list]) : [];
        }

        foreach ($data['werkervaring'] as $index => $entry) {
            if (is_array($entry)) {
                $data['werkervaring'][$index] = [
                    'via' => null,
                    'subtitel' => null,
                    'technologieen' => [],
                    ...$entry,
                    'bullets' => array_values((array) ($entry['bullets'] ?? [])),
                ];
            }
        }

        foreach ($data['projecten'] as $index => $entry) {
            if (is_array($entry)) {
                $data['projecten'][$index] = ['technologieen' => [], 'periode' => null, 'omschrijving' => '', ...$entry];
            }
        }

        return $data;
    }
}
