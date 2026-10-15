<?php

namespace App\Ai\Agents;

use App\Ai\AiGateway;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Domain\Assignments\Data\AssignmentSchema;

/**
 * Zet de tekst van een opdracht om naar de velden van Bijlage B (§9.1, FR-O1).
 */
class AssignmentExtractor
{
    public const PROMPT = 'assignment-extractor';

    /** Langere teksten worden ingekort; de rest is meestal standaardtekst van de intermediair. */
    public const MAX_INPUT_CHARS = 24_000;

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AiProviderException
     */
    public function extract(TrackedRun $run, string $title, string $text, bool $userInitiated = false): array
    {
        $body = mb_substr($text, 0, self::MAX_INPUT_CHARS);

        return $this->gateway->structured(
            run: $run,
            task: AiTask::Extract,
            system: $this->prompts->get(self::PROMPT),
            user: "<opdracht>\nTitel: {$title}\n\n{$body}\n</opdracht>",
            schema: AssignmentSchema::jsonSchema(),
            validator: fn (array $data): ?string => AssignmentSchema::validate($data),
            userInitiated: $userInitiated,
        );
    }
}
