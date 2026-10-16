<?php

namespace App\Filament\Resources\Sources\Tables;

use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\Source;
use App\Filament\Resources\Sources\SourceActions;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class SourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('type')->label('Type')->options(SourceType::class),
                TernaryFilter::make('enabled')->label('Aan'),
            ])
            ->columns([
                TextColumn::make('name')->label('Bron')->searchable()->sortable()
                    ->description(fn (Source $record): string => (string) $record->access_method),
                TextColumn::make('type')->label('Type')->badge()->color('gray'),
                IconColumn::make('enabled')->label('Aan')->boolean(),
                TextColumn::make('tos_reviewed_at')->label('ToS-check')->date('d-m-Y')->placeholder('Ontbreekt')->color(fn (Source $record): string => $record->hasToSCheck() ? 'success' : 'warning'),
                TextColumn::make('poll_interval_minutes')->label('Interval')->suffix(' min'),
                TextColumn::make('last_run_at')->label('Laatste run')->since()->dateTimeTooltip('d-m-Y H:i')->placeholder('—'),
                TextColumn::make('raw_postings_count')->label('Publicaties')->counts('rawPostings'),
                TextColumn::make('health')->label('Status')->badge()
                    ->state(fn (Source $record): string => match (true) {
                        $record->type === SourceType::Alert => $record->enabled ? 'Alert ingesteld' : 'Alert nog instellen',
                        ! $record->type->canFetch() => 'Connector ontbreekt',
                        ! $record->enabled => 'Uit',
                        $record->health_warning !== null => 'Waarschuwing',
                        $record->consecutive_failures > 0 => $record->consecutive_failures.'× fout',
                        default => 'Actief',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Actief', 'Alert ingesteld' => 'success',
                        'Uit', 'Connector ontbreekt' => 'gray',
                        'Alert nog instellen' => 'warning',
                        default => 'danger',
                    })
                    ->tooltip(fn (Source $record): ?string => $record->health_warning),
            ])
            ->toolbarActions([
                BulkAction::make('approveAndEnable')
                    ->label('ToS-check akkoord en aanzetten')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->modalDescription('Je bevestigt dat je de ToS-samenvatting van de geselecteerde bronnen hebt gelezen en akkoord bent. De datum van vandaag wordt vastgelegd en de bronnen gaan aan. Job alerts worden als "ingesteld" gemarkeerd.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $enabled = 0;

                        foreach ($records as $source) {
                            if (! $source instanceof Source || ($source->type !== SourceType::Alert && blank($source->tos_notes))) {
                                continue;
                            }

                            $source->forceFill(['tos_reviewed_at' => $source->tos_reviewed_at ?? now(), 'enabled' => true])->save();
                            $enabled++;
                        }

                        Notification::make()->success()->title($enabled.' '.($enabled === 1 ? 'bron' : 'bronnen').' aangezet')
                            ->body($enabled < $records->count() ? 'Bronnen zonder ToS-samenvatting zijn overgeslagen.' : null)
                            ->send();
                    }),
            ])
            ->recordActions([
                SourceActions::testRun(),
                SourceActions::runNow(),
                EditAction::make(),
            ]);
    }
}
