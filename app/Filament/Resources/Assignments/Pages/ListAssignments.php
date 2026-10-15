<?php

namespace App\Filament\Resources\Assignments\Pages;

use App\Filament\Resources\Assignments\AssignmentActions;
use App\Filament\Resources\Assignments\AssignmentResource;
use Filament\Resources\Pages\ListRecords;

class ListAssignments extends ListRecords
{
    protected static string $resource = AssignmentResource::class;

    public function getSubheading(): string
    {
        return 'Opdrachten uit alle bronnen, ontdubbeld. Standaard alleen open opdrachten over softwareontwikkeling; pas de filters aan om alles te zien.';
    }

    protected function getHeaderActions(): array
    {
        return [AssignmentActions::add()];
    }
}
