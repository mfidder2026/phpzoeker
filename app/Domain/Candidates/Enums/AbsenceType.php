<?php

namespace App\Domain\Candidates\Enums;

use Filament\Support\Contracts\HasLabel;

enum AbsenceType: string implements HasLabel
{
    case Vakantie = 'vakantie';
    case Verlof = 'verlof';
    case Overig = 'overig';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
