<?php

namespace App\Ai\Runs;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AiRunStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'In wachtrij',
            self::Running => 'Bezig',
            self::Succeeded => 'Geslaagd',
            self::Failed => 'Mislukt',
            self::Cancelled => 'Geannuleerd',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'warning',
            self::Running => 'primary',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
