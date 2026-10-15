<?php

namespace App\Domain\Sources\Contracts;

use App\Domain\Sources\Data\ConnectorTestResult;
use App\Domain\Sources\Data\RawPostingData;
use App\Domain\Sources\Models\Source;
use DateTimeImmutable;

/**
 * Connector per brontype (§8.3). Een connector maakt geen opdrachten aan; hij levert alleen
 * ruwe publicaties op. Fouten worden als exception gegooid en per bron afgehandeld (FR-B3).
 */
interface SourceConnector
{
    /** Unieke sleutel, bijv. 'feed', 'mailbox_alert'. */
    public function key(): string;

    /**
     * Haal nieuwe publicaties op sinds $since.
     *
     * @return iterable<RawPostingData>
     */
    public function fetch(Source $source, ?DateTimeImmutable $since): iterable;

    /** Droog draaien voor de knop "Test run": niets opslaan. */
    public function test(Source $source): ConnectorTestResult;
}
