<?php

namespace App\Domain\Assignments\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AssignmentStatus: string implements HasColor, HasLabel
{
    case Nieuw = 'nieuw';
    case Gematcht = 'gematcht';
    case Shortlist = 'shortlist';
    case Genegeerd = 'genegeerd';
    case Aangeboden = 'aangeboden';
    case Gesloten = 'gesloten';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nieuw => 'Nieuw',
            self::Gematcht => 'Gematcht',
            self::Shortlist => 'Shortlist',
            self::Genegeerd => 'Genegeerd',
            self::Aangeboden => 'Aangeboden',
            self::Gesloten => 'Gesloten',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Nieuw => 'info',
            self::Gematcht => 'primary',
            self::Shortlist => 'warning',
            self::Genegeerd => 'gray',
            self::Aangeboden => 'success',
            self::Gesloten => 'gray',
        };
    }
}
