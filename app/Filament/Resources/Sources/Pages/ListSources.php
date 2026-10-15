<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\SourceActions;
use App\Filament\Resources\Sources\SourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSources extends ListRecords
{
    protected static string $resource = SourceResource::class;

    public function getSubheading(): string
    {
        return 'Waar opdrachten vandaan komen. Volgorde van voorkeur: officiële API of feed, job-alert-mails, toegestane openbare pagina\'s, handmatige invoer (§8.1).';
    }

    protected function getHeaderActions(): array
    {
        return [SourceActions::searchAll(), CreateAction::make()->label('Bron toevoegen')->color('gray')];
    }
}
