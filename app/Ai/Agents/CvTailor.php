<?php

namespace App\Ai\Agents;

use App\Ai\AiGateway;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Data\CandidateProfileSchema;
use App\Domain\Matching\Support\ProfileFacts;

/**
 * Maakt een getailord CV uit het master-profiel (§12.2). Het profiel gaat zonder naam,
 * contactgegevens en geboortedatum mee (R4); de naam wordt daarna lokaal teruggezet.
 */
class CvTailor
{
    public const PROMPT = 'cv-tailor';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $schema = CandidateProfileSchema::jsonSchema();
        $schema['required'][] = 'aansluiting_op_opdracht';
        $schema['required'][] = 'changes';
        $schema['properties']['aansluiting_op_opdracht'] = ['type' => 'array', 'items' => [
            'type' => 'object',
            'required' => ['eis_id', 'eis', 'invulling'],
            'properties' => ['eis_id' => ['type' => 'string'], 'eis' => ['type' => 'string'], 'invulling' => ['type' => 'string']],
        ]];
        $schema['properties']['changes'] = ['type' => 'array', 'items' => [
            'type' => 'object',
            'required' => ['sectie', 'wijziging'],
            'properties' => ['sectie' => ['type' => 'string'], 'wijziging' => ['type' => 'string']],
        ]];

        return $schema;
    }

    /**
     * @param  array<string, mixed>|null  $assessment  Bijlage C
     * @param  list<string>  $allowedTitles
     * @param  array<string, mixed>|null  $research  resultaat van `WebResearcher`
     * @return array<string, mixed>
     *
     * @throws AiProviderException
     */
    public function tailor(TrackedRun $run, Assignment $assignment, ProfileFacts $facts, ?array $assessment, array $allowedTitles, string $language, ?array $research = null): array
    {
        $json = fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $opdracht = [
            'titel' => $assignment->title,
            'eindklant' => $assignment->client_name,
            'samenvatting' => $assignment->summary,
            'senioriteit' => $assignment->seniority,
            'technologieen' => $assignment->technologies ?? [],
            'eisen' => $assignment->requirements ?? [],
            'wensen' => $assignment->wishes ?? [],
        ];

        $user = "<taal>{$language}</taal>\n\n"
            ."<toegestane_titels>\n{$json($allowedTitles)}\n</toegestane_titels>\n\n"
            ."<opdracht>\n{$json($opdracht)}\n</opdracht>\n\n"
            ."<beoordeling>\n{$json($assessment === null ? null : ['eisen' => $assessment['eisen'] ?? [], 'wensen' => $assessment['wensen'] ?? []])}\n</beoordeling>\n\n"
            .($research !== null ? "<onderzoek>\n{$json($research)}\n</onderzoek>\n\n" : '')
            ."<profiel>\n{$json($facts->forPrompt())}\n</profiel>";

        $data = $this->gateway->structured(
            run: $run,
            task: AiTask::Cv,
            system: $this->prompts->get(self::PROMPT),
            user: $user,
            schema: self::jsonSchema(),
            validator: function (array $data): ?string {
                $errors = CandidateProfileSchema::validate(CvImporter::normalize($data));

                if (! is_array($data['aansluiting_op_opdracht'] ?? null)) {
                    $errors[] = 'aansluiting_op_opdracht ontbreekt.';
                }

                return $errors === [] ? null : implode(' ', array_slice($errors, 0, 8));
            },
            userInitiated: true,
        );

        $content = CvImporter::normalize($data);
        $content['naam'] = (string) ($facts->profile['naam'] ?? $facts->candidate->name);
        $content['aansluiting_op_opdracht'] = array_values(array_filter((array) ($data['aansluiting_op_opdracht'] ?? []), 'is_array'));
        $content['changes'] = array_slice(array_values(array_filter((array) ($data['changes'] ?? []), 'is_array')), 0, 12);

        return $content;
    }
}
