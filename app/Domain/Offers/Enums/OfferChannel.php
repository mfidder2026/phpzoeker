<?php

namespace App\Domain\Offers\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Kanaal van een aanbieding (§11.5, §13.6).
 */
enum OfferChannel: string implements HasColor, HasLabel
{
    case Email = 'email';
    case Platform = 'platform';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'Mail',
            self::Platform => 'Platform',
            self::Manual => 'Handmatig',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Email => 'info',
            self::Platform => 'warning',
            self::Manual => 'gray',
        };
    }
}
