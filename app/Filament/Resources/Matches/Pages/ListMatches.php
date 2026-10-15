<?php

namespace App\Filament\Resources\Matches\Pages;

use App\Filament\Resources\Matches\MatchResource;
use App\Filament\Support\Access;
use Filament\Resources\Pages\ListRecords;

class ListMatches extends ListRecords
{
    protected static string $resource = MatchResource::class;

    public function getSubheading(): string
    {
        return Access::isStaff()
            ? 'Standaard sterke en mogelijke matches voor open opdrachten. Zwakke en afgevallen matches (met de reden) vind je via het filter Label.'
            : 'Opdrachten die bij jou passen. Geef met 👍 of 👎 aan of je interesse hebt.';
    }
}
