<?php

namespace App\Domain\Offers\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Verzendstatus van een uitgaande mail (§13.5).
 */
enum OutboundEmailStatus: string implements HasColor, HasLabel
{
    case Concept = 'concept';
    case Goedgekeurd = 'goedgekeurd';
    case Verzenden = 'verzenden';
    case Verzonden = 'verzonden';
    case Fout = 'fout';

    public function getLabel(): string
    {
        return match ($this) {
            self::Concept => 'Concept',
            self::Goedgekeurd => 'Goedgekeurd',
            self::Verzenden => 'Wordt verzonden',
            self::Verzonden => 'Verzonden',
            self::Fout => 'Fout',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Concept => 'gray',
            self::Goedgekeurd => 'info',
            self::Verzenden => 'warning',
            self::Verzonden => 'success',
            self::Fout => 'danger',
        };
    }
}
