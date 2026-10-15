<?php

namespace App\Domain\Matching\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Labels per §10.5. `Afgevallen` = een harde filter is niet gehaald (§10.2).
 */
enum MatchLabel: string implements HasColor, HasLabel
{
    case Sterk = 'sterk';
    case Mogelijk = 'mogelijk';
    case Zwak = 'zwak';
    case Afgevallen = 'afgevallen';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sterk => 'Sterke match',
            self::Mogelijk => 'Mogelijke match',
            self::Zwak => 'Zwakke match',
            self::Afgevallen => 'Afgevallen',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sterk => 'success',
            self::Mogelijk => 'warning',
            self::Zwak => 'gray',
            self::Afgevallen => 'danger',
        };
    }

    public function isPromising(): bool
    {
        return $this === self::Sterk || $this === self::Mogelijk;
    }
}
