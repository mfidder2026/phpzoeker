<?php

namespace App\Domain\Candidates\Enums;

use Filament\Support\Contracts\HasLabel;

enum Seniority: string implements HasLabel
{
    case Junior = 'junior';
    case Medior = 'medior';
    case Senior = 'senior';
    case Lead = 'lead';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Positie op de senioriteitsladder, voor het berekenen van niveauverschil.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Junior => 1,
            self::Medior => 2,
            self::Senior => 3,
            self::Lead => 4,
        };
    }
}
