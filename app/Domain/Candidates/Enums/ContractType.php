<?php

namespace App\Domain\Candidates\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContractType: string implements HasLabel
{
    case Detachering = 'detachering';
    case Zzp = 'zzp';
    case Beide = 'beide';

    public function getLabel(): string
    {
        return match ($this) {
            self::Detachering => 'Detachering',
            self::Zzp => 'Zzp',
            self::Beide => 'Beide',
        };
    }
}
