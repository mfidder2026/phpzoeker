<?php

namespace App\Domain\Offers\Exceptions;

use App\Domain\Offers\Models\Offer;
use RuntimeException;

/**
 * Een kandidaat heeft al een actieve aanbieding in dezelfde dedupe-groep (R7, §11.4).
 */
class DuplicateOfferException extends RuntimeException
{
    public function __construct(string $message, public readonly ?Offer $existing = null)
    {
        parent::__construct($message);
    }
}
