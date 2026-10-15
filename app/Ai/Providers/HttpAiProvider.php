<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\AiProvider;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Support\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

abstract class HttpAiProvider implements AiProvider
{
    /**
     * @param  array<string, string>  $extraOptions
     */
    public function __construct(
        protected string $baseUrl,
        #[SensitiveParameter] protected ?string $apiKey,
        protected array $extraOptions = [],
    ) {
        SecretRedactor::register($apiKey);
    }

    abstract protected function authorize(PendingRequest $request): PendingRequest;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{body: array<string, mixed>, latency_ms: int}
     *
     * @throws AiProviderException
     */
    protected function post(string $path, array $payload, string $model, int $timeoutSeconds): array
    {
        $started = hrtime(true);

        try {
            $response = $this->authorize(Http::baseUrl($this->baseUrl))
                ->acceptJson()
                ->asJson()
                ->timeout($timeoutSeconds)
                ->connectTimeout(min(10, $timeoutSeconds))
                ->withUserAgent('PHPZoeker/1.0')
                ->post($path, $payload);
        } catch (ConnectionException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'timed out')) {
                throw AiProviderException::timeout();
            }

            throw AiProviderException::unreachable($exception->getMessage());
        }

        $latency = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->guardResponse($response, $model);

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return ['body' => $body, 'latency_ms' => $latency];
    }

    /**
     * @throws AiProviderException
     */
    protected function guardResponse(Response $response, string $model): void
    {
        if ($response->successful()) {
            return;
        }

        throw AiProviderException::fromHttpStatus($response->status(), $model, $response->body());
    }
}
