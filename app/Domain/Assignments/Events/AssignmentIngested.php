<?php

namespace App\Domain\Assignments\Events;

use App\Domain\Assignments\Models\Assignment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Een opdracht is aangemaakt of via een extra bron bijgewerkt (§6 stap 4). De matching
 * (fase 3) luistert hierop.
 */
class AssignmentIngested
{
    use Dispatchable;

    public function __construct(public Assignment $assignment, public bool $isNew) {}
}
