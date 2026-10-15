<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    public function getSubheading(): string
    {
        return 'Opdrachtgevers, intermediairs en platforms. Worden bij het ophalen van opdrachten automatisch herkend; dubbelingen kun je samenvoegen.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Relatie toevoegen')];
    }
}
