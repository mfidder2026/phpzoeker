<?php

namespace App\Ai\Support;

final readonly class AiRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>|null  $schema  JSON Schema voor gestructureerde output
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
        public ?array $schema = null,
        public int $maxOutputTokens = 1024,
        public int $timeoutSeconds = 120,
        public float $temperature = 0.2,
    ) {}

    /**
     * @param  array<string, mixed>|null  $schema
     */
    public static function make(string $model, string $system, string $user, ?array $schema = null, int $maxOutputTokens = 1024, int $timeoutSeconds = 120): self
    {
        return new self($model, $system, [['role' => 'user', 'content' => $user]], $schema, $maxOutputTokens, $timeoutSeconds);
    }

    public function withExtraUserMessage(string $assistantReply, string $userMessage): self
    {
        return new self(
            $this->model,
            $this->system,
            [...$this->messages, ['role' => 'assistant', 'content' => $assistantReply], ['role' => 'user', 'content' => $userMessage]],
            $this->schema,
            $this->maxOutputTokens,
            $this->timeoutSeconds,
            $this->temperature,
        );
    }
}
