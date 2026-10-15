<?php

namespace App\Ai\Support;

/**
 * Houdt bij welke geheimen (API-sleutels) in omloop zijn en vervangt ze in tekst door een
 * gemaskeerde vorm. Gebruikt in foutmeldingen, logs en `ai_runs.error_message` (R9).
 */
final class SecretRedactor
{
    /**
     * @var array<string, true>
     */
    private static array $secrets = [];

    public static function register(?string $secret): void
    {
        if ($secret !== null && mb_strlen($secret) >= 8) {
            self::$secrets[$secret] = true;
        }
    }

    public static function redact(string $text): string
    {
        foreach (array_keys(self::$secrets) as $secret) {
            $text = str_replace($secret, '[sleutel verborgen]', $text);
        }

        return (string) preg_replace('/(Bearer|x-api-key:?)\s+[A-Za-z0-9._\-]{12,}/i', '$1 [sleutel verborgen]', $text);
    }

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    public static function redactArray(array $context): array
    {
        array_walk_recursive($context, function (mixed &$value): void {
            if (is_string($value)) {
                $value = self::redact($value);
            }
        });

        return $context;
    }

    public static function flush(): void
    {
        self::$secrets = [];
    }
}
