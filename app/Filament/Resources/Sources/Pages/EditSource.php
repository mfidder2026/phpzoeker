<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Domain\Sources\Models\Source;
use App\Filament\Resources\Sources\Pages\Concerns\GuardsSourceForm;
use App\Filament\Resources\Sources\SourceActions;
use App\Filament\Resources\Sources\SourceResource;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Source $record
 */
class EditSource extends EditRecord
{
    use GuardsSourceForm;

    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [SourceActions::testRun(), SourceActions::runNow()];
    }

    /**
     * Mailboxwachtwoord en client-secret gaan nooit terug naar de browser (zoals R9).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $config = $this->record->config ?? [];
        unset($config['password'], $config['client_secret']);
        $data['config'] = $config;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->guardSourceData($data, $this->record->config ?? []);
    }
}
