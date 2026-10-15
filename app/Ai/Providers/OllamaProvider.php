<?php

namespace App\Ai\Providers;

use App\Ai\Support\AiRequest;
use App\Ai\Support\AiResponse;
use Illuminate\Http\Client\PendingRequest;

/**
 * Ollama-API (`/api/chat`), zowel Ollama Cloud (https://ollama.com) als een lokale server.
 * Gestructureerde output via het `format`-veld met een JSON Schema.
 */
class OllamaProvider extends HttpAiProvider
{
    protected function authorize(PendingRequest $request): PendingRequest
    {
        return $this->apiKey !== null && $this->apiKey !== '' ? $request->withToken($this->apiKey) : $request;
    }

    public function chat(AiRequest $request): AiResponse
    {
        $messages = [['role' => 'system', 'content' => $request->system], ...$request->messages];

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'num_predict' => $request->maxOutputTokens,
                'temperature' => $request->temperature,
            ],
        ];

        if (($this->extraOptions['think'] ?? 'false') !== 'true') {
            $payload['think'] = false;
        }

        if ($request->schema !== null) {
            $payload['format'] = $request->schema;
        }

        ['body' => $body, 'latency_ms' => $latency] = $this->post('/api/chat', $payload, $request->model, $request->timeoutSeconds);

        /** @var array{message?: array{content?: string}, model?: string, prompt_eval_count?: int, eval_count?: int} $body */
        return new AiResponse(
            text: (string) ($body['message']['content'] ?? ''),
            model: (string) ($body['model'] ?? $request->model),
            inputTokens: (int) ($body['prompt_eval_count'] ?? 0),
            outputTokens: (int) ($body['eval_count'] ?? 0),
            latencyMs: $latency,
        );
    }
}
