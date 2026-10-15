<?php

namespace App\Domain\Offers\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Akkoord van de kandidaat (§11.3).
 */
enum ConsentStatus: string implements HasColor, HasLabel
{
    case NietNodig = 'niet_nodig';
    case Gevraagd = 'gevraagd';
    case Akkoord = 'akkoord';
    case Mondeling = 'mondeling';
    case Geweigerd = 'geweigerd';

    public function getLabel(): string
    {
        return match ($this) {
            self::NietNodig => 'Niet nodig',
            self::Gevraagd => 'Gevraagd',
            self::Akkoord => 'Akkoord',
            self::Mondeling => 'Mondeling akkoord',
            self::Geweigerd => 'Geweigerd',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NietNodig => 'gray',
            self::Gevraagd => 'warning',
            self::Akkoord => 'success',
            self::Mondeling => 'success',
            self::Geweigerd => 'danger',
        };
    }
}
