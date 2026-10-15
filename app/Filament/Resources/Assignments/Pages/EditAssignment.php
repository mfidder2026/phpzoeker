<?php

namespace App\Filament\Resources\Assignments\Pages;

use App\Domain\Assignments\Models\Assignment;
use App\Filament\Resources\Assignments\AssignmentResource;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Assignment $record
 */
class EditAssignment extends EditRecord
{
    protected static string $resource = AssignmentResource::class;

    /**
     * Velden die een mens wijzigt, worden vergrendeld (§9.1).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $locked = $this->record->locked_fields ?? [];
        $probe = (clone $this->record)->fill($data);

        foreach (array_keys($data) as $field) {
            if ($probe->isDirty($field)) {
                $locked[] = $field;
            }
        }

        $data['locked_fields'] = array_values(array_unique($locked));

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return AssignmentResource::getUrl('view', ['record' => $this->record]);
    }
}
