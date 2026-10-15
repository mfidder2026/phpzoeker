<?php

namespace App\Domain\Sources\Contracts;

use App\Domain\Sources\Data\RawPostingData;
use App\Domain\Sources\Models\Source;

/**
 * Connector die een publicatie na opslaan als verwerkt markeert (bijv. mail als gelezen).
 */
interface AcknowledgesPostings
{
    public function acknowledge(Source $source, RawPostingData $posting): void;
}
