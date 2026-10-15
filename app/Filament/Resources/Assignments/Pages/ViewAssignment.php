<?php

namespace App\Filament\Resources\Assignments\Pages;

use App\Domain\Assignments\Models\Assignment;
use App\Filament\Resources\Assignments\AssignmentActions;
use App\Filament\Resources\Assignments\AssignmentResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property Assignment $record
 */
class ViewAssignment extends ViewRecord
{
    protected static string $resource = AssignmentResource::class;

    public function getTitle(): string
    {
        return $this->record->title;
    }

    public function getSubheading(): string
    {
        return collect([
            $this->record->client_name,
            $this->record->intermediary_name ? 'via '.$this->record->intermediary_name : null,
            $this->record->city,
            $this->record->deadline_at ? 'deadline '.$this->record->deadline_at->format('d-m-Y H:i') : null,
        ])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            AssignmentActions::match(),
            AssignmentActions::mergeDuplicate(),
            AssignmentActions::ignore(),
            AssignmentActions::reopen(),
            EditAction::make()->label('Velden corrigeren'),
            ActionGroup::make([
                AssignmentActions::reextract(),
                AssignmentActions::notDuplicate(),
            ]),
        ];
    }
}
