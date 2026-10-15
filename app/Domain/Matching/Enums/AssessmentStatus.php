<?php

namespace App\Domain\Matching\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Stand van de AI-beoordeling van een match.
 */
enum AssessmentStatus: string implements HasLabel
{
    /** Prefilter of harde filter niet gehaald: geen AI-aanroep nodig (§10.1). */
    case NietNodig = 'niet_nodig';
    case Wacht = 'wacht';
    case Klaar = 'klaar';
    /** Dagbudget voor matching op; wordt later opnieuw opgepakt (§18.3). */
    case Budget = 'budget';
    case Mislukt = 'mislukt';

    public function getLabel(): string
    {
        return match ($this) {
            self::NietNodig => 'Niet nodig',
            self::Wacht => 'In de wachtrij',
            self::Klaar => 'Beoordeeld',
            self::Budget => 'Wacht op AI-budget',
            self::Mislukt => 'Mislukt',
        };
    }
}
