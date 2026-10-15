<?php

namespace App\Domain\Sources\Connectors;

use App\Domain\Sources\Contracts\SourceConnector;
use App\Domain\Sources\Data\ConnectorTestResult;
use App\Domain\Sources\Models\Source;
use DateTimeImmutable;

/**
 * Bron "Handmatig" (§8.6): publicaties komen binnen via het formulier "Opdracht toevoegen" of
 * doorgestuurde mails, niet via periodiek ophalen.
 */
class ManualConnector implements SourceConnector
{
    public function key(): string
    {
        return 'manual';
    }

    public function fetch(Source $source, ?DateTimeImmutable $since): iterable
    {
        return [];
    }

    public function test(Source $source): ConnectorTestResult
    {
        return new ConnectorTestResult(true, 'Handmatige bron: opdrachten komen binnen via "Opdracht toevoegen".');
    }
}
