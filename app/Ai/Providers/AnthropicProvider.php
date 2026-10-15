<?php

namespace App\Ai\Providers;

use App\Ai\Support\AiRequest;
use App\Ai\Support\AiResponse;
use Illuminate\Http\Client\PendingRequest;

/**
 * Anthropic Messages API. Gestructureerde output via de schema-instructie in de systeemprompt;
 * de output wordt daarna altijd gevalideerd (§18.1).
 */
class AnthropicProvider extends HttpAiProvider
{
    protected function authorize(PendingRequest $request): PendingRequest
    {
        return $request->withHeaders([
            'x-api-key' => (string) $this->apiKey,
            'anthropic-version' => $this->extraOptions['api_version'] ?? '2023-06-01',
        ]);
    }

    public function chat(AiRequest $request): AiResponse
    {
        $system = $request->system;

        if ($request->schema !== null) {
            $system .= "\n\nAntwoord uitsluitend met één JSON-object volgens dit JSON Schema, zonder toelichting:\n"
                .json_encode($request->schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        ['body' => $body, 'latency_ms' => $latency] = $this->post('/v1/messages', [
            'model' => $request->model,
            'system' => $system,
            'messages' => $request->messages,
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ], $request->model, $request->timeoutSeconds);

        /** @var array{content?: list<array{type?: string, text?: string}>, model?: string, usage?: array{input_tokens?: int, output_tokens?: int}} $body */
        $text = collect($body['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return new AiResponse(
            text: $text,
            model: (string) ($body['model'] ?? $request->model),
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            latencyMs: $latency,
        );
    }
}
