<?php

namespace App\Domain\Matching\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Oordeel per eis of wens (Bijlage C) met de waarde uit §10.3.
 */
enum Verdict: string implements HasColor, HasLabel
{
    case Voldoet = 'voldoet';
    case Deels = 'deels';
    case VoldoetNiet = 'voldoet_niet';
    case Onbekend = 'onbekend';

    public function getLabel(): string
    {
        return match ($this) {
            self::Voldoet => 'Voldoet',
            self::Deels => 'Deels',
            self::VoldoetNiet => 'Voldoet niet',
            self::Onbekend => 'Onbekend',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Voldoet => 'success',
            self::Deels => 'warning',
            self::VoldoetNiet => 'danger',
            self::Onbekend => 'gray',
        };
    }

    public function value(): float
    {
        return match ($this) {
            self::Voldoet => 1.0,
            self::Deels => 0.5,
            self::Onbekend => 0.25,
            self::VoldoetNiet => 0.0,
        };
    }
}
