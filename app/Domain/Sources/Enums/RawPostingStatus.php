<?php

namespace App\Domain\Sources\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RawPostingStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Irrelevant = 'irrelevant';
    case Split = 'split';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Te verwerken',
            self::Processed => 'Verwerkt',
            self::Duplicate => 'Dubbel',
            self::Irrelevant => 'Niet relevant',
            self::Split => 'Opgesplitst',
            self::Failed => 'Mislukt',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Processed => 'success',
            self::Duplicate => 'gray',
            self::Irrelevant => 'gray',
            self::Split => 'info',
            self::Failed => 'danger',
        };
    }
}
