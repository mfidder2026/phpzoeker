<?php

namespace App\Ai\Providers;

use App\Ai\Support\AiRequest;
use App\Ai\Support\AiResponse;
use Illuminate\Http\Client\PendingRequest;

/**
 * OpenAI Chat Completions en elk OpenAI-compatibel endpoint (Azure, vLLM, LiteLLM, ...).
 */
class OpenAiCompatibleProvider extends HttpAiProvider
{
    protected function authorize(PendingRequest $request): PendingRequest
    {
        $request = $this->apiKey !== null && $this->apiKey !== '' ? $request->withToken($this->apiKey) : $request;

        if (isset($this->extraOptions['organization'])) {
            $request = $request->withHeaders(['OpenAI-Organization' => $this->extraOptions['organization']]);
        }

        return $request;
    }

    public function chat(AiRequest $request): AiResponse
    {
        $payload = [
            'model' => $request->model,
            'messages' => [['role' => 'system', 'content' => $request->system], ...$request->messages],
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ];

        if ($request->schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'output', 'schema' => $request->schema],
            ];
        }

        ['body' => $body, 'latency_ms' => $latency] = $this->post('/v1/chat/completions', $payload, $request->model, $request->timeoutSeconds);

        /** @var array{choices?: list<array{message?: array{content?: string|null}}>, model?: string, usage?: array{prompt_tokens?: int, completion_tokens?: int}} $body */
        return new AiResponse(
            text: (string) ($body['choices'][0]['message']['content'] ?? ''),
            model: (string) ($body['model'] ?? $request->model),
            inputTokens: (int) ($body['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['completion_tokens'] ?? 0),
            latencyMs: $latency,
        );
    }
}
