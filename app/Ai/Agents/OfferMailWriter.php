<?php

namespace App\Ai\Agents;

use App\Ai\AiGateway;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Domain\Assignments\Models\Assignment;
use Illuminate\Support\Facades\Validator;

/**
 * Schrijft alleen de introductie en de bullets van de aanbiedingsmail (§13.3); bij
 * platformindiening ook de toelichting per eis (§13.6). Tarief, datums, ontvangers en
 * handtekening komen uit data en templates, zodat het model die niet kan verzinnen.
 */
class OfferMailWriter
{
    public const PROMPT = 'offer-mail-writer';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  array<string, mixed>  $tailoredCv  inhoud van het goedgekeurde CV (zonder naam)
     * @param  array<string, mixed>|null  $assessment
     * @return array{intro: string, bullets: list<string>, eis_antwoorden: list<array{id: string, antwoord: string}>}
     *
     * @throws AiProviderException
     */
    public function write(TrackedRun $run, Assignment $assignment, string $candidateName, string $cvTitle, array $tailoredCv, ?array $assessment, string $language, bool $platform, ?int $maxChars = null): array
    {
        $json = fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        unset($tailoredCv['changes']);
        $tailoredCv['naam'] = $candidateName;

        $opdracht = [
            'titel' => $assignment->title,
            'referentie' => $assignment->reference,
            'eindklant' => $assignment->client_name,
            'eisen' => $assignment->requirements ?? [],
            'wensen' => $assignment->wishes ?? [],
        ];

        $user = "<taal>{$language}</taal>\n<platform>".($platform ? 'ja' : 'nee')."</platform>\n<max_tekens>".($maxChars ?? '')."</max_tekens>\n<cv_titel>{$cvTitle}</cv_titel>\n\n"
            ."<opdracht>\n{$json($opdracht)}\n</opdracht>\n\n"
            ."<beoordeling>\n{$json($assessment === null ? null : ['eisen' => $assessment['eisen'] ?? [], 'sterke_punten' => $assessment['sterke_punten'] ?? []])}\n</beoordeling>\n\n"
            ."<profiel>\n{$json($tailoredCv)}\n</profiel>";

        $data = $this->gateway->structured(
            run: $run,
            task: AiTask::Mail,
            system: $this->prompts->get(self::PROMPT),
            user: $user,
            schema: [
                'type' => 'object',
                'required' => ['intro', 'bullets', 'eis_antwoorden'],
                'properties' => [
                    'intro' => ['type' => 'string'],
                    'bullets' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 3, 'maxItems' => 5],
                    'eis_antwoorden' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'required' => ['id', 'antwoord'],
                        'properties' => ['id' => ['type' => 'string'], 'antwoord' => ['type' => 'string']],
                    ]],
                ],
            ],
            validator: function (array $data): ?string {
                $validator = Validator::make($data, [
                    'intro' => ['required', 'string'],
                    'bullets' => ['required', 'array', 'min:3', 'max:5'],
                    'bullets.*' => ['string'],
                    'eis_antwoorden' => ['present', 'array'],
                    'eis_antwoorden.*.id' => ['required', 'string'],
                    'eis_antwoorden.*.antwoord' => ['required', 'string'],
                ]);

                return $validator->fails() ? implode(' ', $validator->errors()->all()) : null;
            },
            userInitiated: true,
        );

        $answers = array_values(array_map(fn (array $answer): array => [
            'id' => (string) $answer['id'],
            'antwoord' => $maxChars !== null ? mb_substr((string) $answer['antwoord'], 0, $maxChars) : (string) $answer['antwoord'],
        ], (array) $data['eis_antwoorden']));

        return [
            'intro' => trim((string) $data['intro']),
            'bullets' => array_values(array_map(fn (mixed $bullet): string => trim((string) $bullet), (array) $data['bullets'])),
            'eis_antwoorden' => $platform ? $answers : [],
        ];
    }
}
