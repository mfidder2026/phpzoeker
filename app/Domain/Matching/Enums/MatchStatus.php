<?php

namespace App\Domain\Matching\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Werkstatus van een match (§17). De stappen na "aangeboden" (verzonden, reactie, intake,
 * geplaatst) horen bij de aanbieding (fase 4).
 */
enum MatchStatus: string implements HasColor, HasLabel
{
    case Nieuw = 'nieuw';
    case Bekeken = 'bekeken';
    case Shortlist = 'shortlist';
    case Afgewezen = 'afgewezen';
    case Aangeboden = 'aangeboden';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nieuw => 'Nieuw',
            self::Bekeken => 'Bekeken',
            self::Shortlist => 'Shortlist',
            self::Afgewezen => 'Afgewezen',
            self::Aangeboden => 'Aangeboden',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Nieuw => 'info',
            self::Bekeken => 'gray',
            self::Shortlist => 'warning',
            self::Afgewezen => 'danger',
            self::Aangeboden => 'success',
        };
    }
}
