<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\Pages\Concerns\GuardsSourceForm;
use App\Filament\Resources\Sources\SourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSource extends CreateRecord
{
    use GuardsSourceForm;

    protected static string $resource = SourceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->guardSourceData($data);
    }
}
