<?php

namespace App\Ai\Agents;

use App\Ai\AiGateway;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Domain\Matching\Support\ProfileFacts;
use Illuminate\Support\Facades\Validator;

/**
 * LLM-rechter van de grounding-check (§12.3 stap 2): zoekt beweringen in het getailorde CV die
 * het master-profiel niet onderbouwt.
 */
class CvGroundingJudge
{
    public const PROMPT = 'cv-grounding-judge';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  array<string, mixed>  $tailored
     * @return list<array{claim: string, location: string, reason: string}>
     *
     * @throws AiProviderException
     */
    public function judge(TrackedRun $run, ProfileFacts $facts, array $tailored): array
    {
        $json = fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $tailored['naam'] = 'De kandidaat';
        unset($tailored['changes']);

        $data = $this->gateway->structured(
            run: $run,
            task: AiTask::Grounding,
            system: $this->prompts->get(self::PROMPT),
            user: "<master_profiel>\n{$json($facts->forPrompt())}\n</master_profiel>\n\n<getailord_cv>\n{$json($tailored)}\n</getailord_cv>",
            schema: [
                'type' => 'object',
                'required' => ['unsupported_claims'],
                'properties' => ['unsupported_claims' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['claim', 'location', 'reason'],
                    'properties' => ['claim' => ['type' => 'string'], 'location' => ['type' => 'string'], 'reason' => ['type' => 'string']],
                ]]],
            ],
            validator: function (array $data): ?string {
                $validator = Validator::make($data, [
                    'unsupported_claims' => ['present', 'array'],
                    'unsupported_claims.*.claim' => ['required', 'string'],
                    'unsupported_claims.*.location' => ['present', 'nullable', 'string'],
                    'unsupported_claims.*.reason' => ['present', 'nullable', 'string'],
                ]);

                return $validator->fails() ? implode(' ', $validator->errors()->all()) : null;
            },
            userInitiated: true,
        );

        return array_values(array_map(fn (array $claim): array => [
            'claim' => (string) $claim['claim'],
            'location' => (string) ($claim['location'] ?? ''),
            'reason' => (string) ($claim['reason'] ?? ''),
        ], (array) $data['unsupported_claims']));
    }
}
