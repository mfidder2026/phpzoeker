<?php

namespace App\Domain\Assignments\Enums;

use Filament\Support\Contracts\HasLabel;

enum Sector: string implements HasLabel
{
    case Overheid = 'overheid';
    case SemiOverheid = 'semi_overheid';
    case Zakelijk = 'zakelijk';
    case Onbekend = 'onbekend';

    public function getLabel(): string
    {
        return match ($this) {
            self::Overheid => 'Overheid',
            self::SemiOverheid => 'Semi-overheid',
            self::Zakelijk => 'Zakelijk',
            self::Onbekend => 'Onbekend',
        };
    }
}
