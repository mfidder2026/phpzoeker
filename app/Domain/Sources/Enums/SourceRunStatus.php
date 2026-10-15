<?php

namespace App\Domain\Sources\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SourceRunStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Running => 'Bezig',
            self::Succeeded => 'Geslaagd',
            self::Failed => 'Mislukt',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'primary',
            self::Succeeded => 'success',
            self::Failed => 'danger',
        };
    }
}
