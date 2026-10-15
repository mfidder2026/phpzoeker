<?php

namespace App\Domain\Relations\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrganizationType: string implements HasLabel
{
    case Eindklant = 'eindklant';
    case Intermediair = 'intermediair';
    case Platform = 'platform';

    public function getLabel(): string
    {
        return match ($this) {
            self::Eindklant => 'Eindklant',
            self::Intermediair => 'Intermediair',
            self::Platform => 'Platform',
        };
    }
}
