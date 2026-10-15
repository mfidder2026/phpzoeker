<?php

namespace App\Ai\Agents;

use App\Ai\AiGateway;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use Illuminate\Support\Facades\Validator;

/**
 * Splitst een alert-mail op in losse opdrachten (§8.3, Bijlage B-input).
 */
class AlertMailSplitter
{
    public const PROMPT = 'alert-mail-splitter';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @return list<array{titel: string, url: string|null, tekst: string}>
     *
     * @throws AiProviderException
     */
    public function split(TrackedRun $run, string $subject, string $text): array
    {
        $data = $this->gateway->structured(
            run: $run,
            task: AiTask::Extract,
            system: $this->prompts->get(self::PROMPT),
            user: "<mail>\nOnderwerp: {$subject}\n\n".mb_substr($text, 0, 30_000)."\n</mail>",
            schema: [
                'type' => 'object',
                'required' => ['opdrachten'],
                'properties' => ['opdrachten' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['titel', 'tekst'],
                    'properties' => ['titel' => ['type' => 'string'], 'url' => ['type' => ['string', 'null']], 'tekst' => ['type' => 'string']],
                ]]],
            ],
            validator: function (array $data): ?string {
                $validator = Validator::make($data, [
                    'opdrachten' => ['present', 'array'],
                    'opdrachten.*.titel' => ['required', 'string'],
                    'opdrachten.*.tekst' => ['present', 'nullable', 'string'],
                    'opdrachten.*.url' => ['nullable', 'string'],
                ]);

                return $validator->fails() ? implode(' ', $validator->errors()->all()) : null;
            },
            userInitiated: false,
        );

        $postings = [];

        foreach ((array) $data['opdrachten'] as $item) {
            // Links alleen als ze letterlijk in de mail staan (R5).
            $url = is_string($item['url'] ?? null) && $item['url'] !== '' && str_contains($text, $item['url']) ? $item['url'] : null;

            $postings[] = ['titel' => (string) $item['titel'], 'url' => $url, 'tekst' => (string) ($item['tekst'] ?? '')];
        }

        return $postings;
    }
}
