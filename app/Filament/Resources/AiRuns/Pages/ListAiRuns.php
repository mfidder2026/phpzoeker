<?php

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Resources\AiRuns\Widgets\AiActivityStats;
use Filament\Resources\Pages\ListRecords;

class ListAiRuns extends ListRecords
{
    protected static string $resource = AiRunResource::class;

    public function getSubheading(): string
    {
        return 'Alle AI-taken met voortgang, tokens, kosten en fouten. Werkt live bij.';
    }

    protected function getHeaderWidgets(): array
    {
        return [AiActivityStats::class];
    }
}
