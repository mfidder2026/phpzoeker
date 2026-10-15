<?php

use App\Ai\Exceptions\AiProviderException;
use App\Ai\Support\SecretRedactor;

it('redacts registered secrets and bearer tokens', function () {
    SecretRedactor::register('abc123-super-secret');

    expect(SecretRedactor::redact('key=abc123-super-secret'))->toBe('key=[sleutel verborgen]')
        ->and(SecretRedactor::redact('Authorization: Bearer xyz987654321abcdef'))->toBe('Authorization: Bearer [sleutel verborgen]')
        ->and(SecretRedactor::redactArray(['a' => ['b' => 'abc123-super-secret']]))->toBe(['a' => ['b' => '[sleutel verborgen]']]);
});

it('ignores too short secrets', function () {
    SecretRedactor::register('abc');

    expect(SecretRedactor::redact('abc def'))->toBe('abc def');
});

it('never puts a key in a provider exception message', function () {
    SecretRedactor::register('sk-live-0123456789');

    $exception = AiProviderException::fromHttpStatus(400, 'm', 'bad key sk-live-0123456789');

    expect($exception->getMessage())->not->toContain('sk-live')
        ->and((string) $exception->technicalDetail)->not->toContain('sk-live');
});
