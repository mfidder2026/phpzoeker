<?php

namespace App\Ai\Exceptions;

use App\Ai\Support\SecretRedactor;
use RuntimeException;

/**
 * Fout van een AI-provider, met een melding in gewone taal (§18.5). De melding wordt altijd
 * door de SecretRedactor gehaald, zodat een sleutel nooit in een foutmelding belandt (R9).
 */
class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $technicalDetail = null,
    ) {
        parent::__construct(SecretRedactor::redact($message));
    }

    public static function fromHttpStatus(int $status, string $model, string $body = ''): self
    {
        $detail = SecretRedactor::redact(mb_substr($body, 0, 300));

        return match (true) {
            $status === 401, $status === 403 => new self('unauthorized', 'De API-sleutel is ongeldig of ingetrokken.', $status, $detail),
            $status === 404 => new self('model_not_found', "Model '{$model}' bestaat niet of is niet beschikbaar voor deze sleutel.", $status, $detail),
            $status === 429 => new self('rate_limited', 'Te veel verzoeken of tegoed op; probeer later opnieuw of controleer het account.', $status, $detail),
            $status >= 500 => new self('unavailable', 'De provider is tijdelijk niet bereikbaar.', $status, $detail),
            default => new self('bad_request', "De provider weigerde het verzoek (HTTP {$status}).", $status, $detail),
        };
    }

    public static function timeout(): self
    {
        return new self('timeout', 'De provider is tijdelijk niet bereikbaar (time-out).');
    }

    public static function unreachable(string $detail = ''): self
    {
        return new self('unreachable', 'Het endpoint is niet bereikbaar. Controleer de basis-URL en de netwerkverbinding.', null, SecretRedactor::redact($detail));
    }

    public static function invalidOutput(string $message): self
    {
        return new self('invalid_output', $message);
    }

    public static function notConfigured(string $message): self
    {
        return new self('not_configured', $message);
    }

    public static function budgetExceeded(): self
    {
        return new self('budget_exceeded', 'Het AI-dagbudget is op; automatische AI-taken zijn gepauzeerd tot morgen.');
    }

    public function isAuthenticationError(): bool
    {
        return $this->errorCode === 'unauthorized';
    }
}
