<?php

namespace App\Domain\Candidates\Events;

use App\Domain\Candidates\Models\Candidate;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Vaste waardes, zoekprofiel of master-profiel zijn gewijzigd; open matches moeten opnieuw
 * worden berekend (FR-K5). De matching-module (fase 3) luistert hierop.
 */
class CandidateValuesChanged
{
    use Dispatchable;

    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(public Candidate $candidate, public array $changedFields) {}
}
