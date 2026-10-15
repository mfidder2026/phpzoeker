<?php

namespace App\Filament\Resources\Sources;

use App\Domain\Sources\ConnectorRegistry;
use App\Domain\Sources\Data\ConnectorTestResult;
use App\Domain\Sources\Jobs\FetchSourceJob;
use App\Domain\Sources\Models\Source;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Acties op een bron: "Test run" (droog, FR-B7) en "Nu ophalen".
 */
final class SourceActions
{
    public static function testRun(): Action
    {
        return Action::make('testRun')
            ->label('Test run')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->modalHeading(fn (Source $record): string => 'Test run — '.$record->name)
            ->modalDescription('Droog draaien: de bron wordt opgehaald maar er wordt niets opgeslagen.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten')
            ->modalContent(function (Source $record) {
                try {
                    $result = app(ConnectorRegistry::class)->for($record->type)->test($record);
                } catch (Throwable $exception) {
                    $result = new ConnectorTestResult(false, $exception->getMessage());
                }

                return view('filament.sources.test-run', ['result' => $result]);
            });
    }

    public static function runNow(): Action
    {
        return Action::make('runNow')
            ->label('Nu ophalen')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Source $record): bool => $record->enabled)
            ->action(function (Source $record): void {
                FetchSourceJob::dispatch($record->id);

                Notification::make()->success()->title('Ophalen gestart')
                    ->body('Nieuwe publicaties verschijnen bij Opdrachten; AI-extractie is zichtbaar in de AI-meter.')
                    ->send();
            });
    }
}
