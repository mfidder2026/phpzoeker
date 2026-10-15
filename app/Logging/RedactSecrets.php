<?php

namespace App\Logging;

use App\Ai\Support\SecretRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Haalt API-sleutels en andere bekende geheimen uit elke logregel (R9, §19.1).
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof \Monolog\Logger) {
            return;
        }

        $monolog->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: SecretRedactor::redact($record->message),
                context: SecretRedactor::redactArray($record->context),
            );
        });
    }
}
