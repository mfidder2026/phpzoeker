<?php

namespace App\Domain\Assignments\Enums;

use Filament\Support\Contracts\HasLabel;

enum WorkMode: string implements HasLabel
{
    case Onsite = 'onsite';
    case Hybride = 'hybride';
    case Remote = 'remote';
    case Onbekend = 'onbekend';

    public function getLabel(): string
    {
        return match ($this) {
            self::Onsite => 'Op locatie',
            self::Hybride => 'Hybride',
            self::Remote => 'Remote',
            self::Onbekend => 'Onbekend',
        };
    }
}
