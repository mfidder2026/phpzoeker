<?php

namespace App\Domain\Assignments\Enums;

use Filament\Support\Contracts\HasLabel;

enum AssignmentContractType: string implements HasLabel
{
    case Detachering = 'detachering';
    case Zzp = 'zzp';
    case Beide = 'beide';
    case Loondienst = 'loondienst';
    case Onbekend = 'onbekend';

    public function getLabel(): string
    {
        return match ($this) {
            self::Detachering => 'Detachering',
            self::Zzp => 'Zzp',
            self::Beide => 'Detachering of zzp',
            self::Loondienst => 'Loondienst',
            self::Onbekend => 'Onbekend',
        };
    }
}
