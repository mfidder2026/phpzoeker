<?php

namespace App\Domain\Candidates\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CandidateStatus: string implements HasColor, HasLabel
{
    case Beschikbaar = 'beschikbaar';
    case InProcedure = 'in_procedure';
    case Geplaatst = 'geplaatst';
    case Inactief = 'inactief';

    public function getLabel(): string
    {
        return match ($this) {
            self::Beschikbaar => 'Beschikbaar',
            self::InProcedure => 'In procedure',
            self::Geplaatst => 'Geplaatst',
            self::Inactief => 'Inactief',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Beschikbaar => 'success',
            self::InProcedure => 'warning',
            self::Geplaatst => 'info',
            self::Inactief => 'gray',
        };
    }

    /**
     * Kandidaten met deze status worden gematcht (§10.2).
     */
    public function isMatchable(): bool
    {
        return in_array($this, [self::Beschikbaar, self::InProcedure], true);
    }
}
