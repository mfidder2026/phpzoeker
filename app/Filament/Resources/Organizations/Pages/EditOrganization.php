<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Domain\Relations\Actions\MergeOrganizations;
use App\Domain\Relations\Models\Organization;
use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Organization $record
 */
class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('applyMeasured')
                ->label('Gemeten termijn als standaard')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->visible(fn (): bool => $this->record->measured_avg_response_days !== null)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Zet de standaard reactietermijn op '.(int) ceil((float) $this->record->measured_avg_response_days).' werkdagen (afgerond naar boven).')
                ->action(function (): void {
                    $this->record->forceFill(['default_response_term_days' => (int) ceil((float) $this->record->measured_avg_response_days)])->save();
                    $this->refreshFormData(['default_response_term_days']);
                    Notification::make()->success()->title('Standaard bijgewerkt')->send();
                }),
            Action::make('merge')
                ->label('Samenvoegen')
                ->icon(Heroicon::OutlinedArrowsPointingIn)
                ->color('gray')
                ->schema([
                    Select::make('target')->label('Samenvoegen met (deze relatie verdwijnt)')->required()->searchable()
                        ->options(fn (): array => Organization::query()->whereNull('merged_into_id')->whereKeyNot($this->record->id)->orderBy('name')->pluck('name', 'id')->all()),
                ])
                ->action(function (array $data): void {
                    $target = Organization::query()->findOrFail((int) $data['target']);
                    app(MergeOrganizations::class)->handle($this->record, $target);

                    Notification::make()->success()->title("Samengevoegd met {$target->name}")->send();
                    $this->redirect(OrganizationResource::getUrl('edit', ['record' => $target]));
                }),
        ];
    }
}
