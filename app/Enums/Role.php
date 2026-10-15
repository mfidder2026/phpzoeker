<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Beheerder = 'beheerder';
    case Accountmanager = 'accountmanager';
    case Reviewer = 'reviewer';
    case Kandidaat = 'kandidaat';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
