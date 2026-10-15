<?php

namespace App\Domain\Matching\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Recommendation: string implements HasColor, HasLabel
{
    case Aanbieden = 'aanbieden';
    case Twijfel = 'twijfel';
    case NietAanbieden = 'niet_aanbieden';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aanbieden => 'Aanbieden',
            self::Twijfel => 'Twijfel',
            self::NietAanbieden => 'Niet aanbieden',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Aanbieden => 'success',
            self::Twijfel => 'warning',
            self::NietAanbieden => 'danger',
        };
    }
}
