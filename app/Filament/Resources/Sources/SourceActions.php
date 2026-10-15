<?php

namespace App\Filament\Resources\Sources;

use App\Domain\Sources\ConnectorRegistry;
use App\Domain\Sources\Data\ConnectorTestResult;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Jobs\FetchSourceJob;
use App\Domain\Sources\Mailbox\MicrosoftLogin;
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

    /**
     * Eenmalig inloggen bij Microsoft 365 voor een mailbox-bron (geen app-registratie nodig).
     */
    public static function microsoftLogin(): Action
    {
        return Action::make('microsoftLogin')
            ->label(fn (Source $record): string => self::isConnected($record) ? 'Opnieuw inloggen bij Microsoft' : 'Inloggen bij Microsoft')
            ->icon(Heroicon::OutlinedKey)
            ->color(fn (Source $record): string => self::isConnected($record) ? 'gray' : 'primary')
            ->visible(fn (Source $record): bool => $record->type === SourceType::MailboxAlert && ($record->config['protocol'] ?? 'imap') === 'graph' && ($record->config['auth'] ?? 'delegated') !== 'app')
            ->modalHeading('Inloggen bij Microsoft 365')
            ->modalWidth('lg')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten')
            ->modalContent(fn (Source $record) => view('filament.sources.microsoft-login', ['source' => $record]));
    }

    public static function microsoftDisconnect(): Action
    {
        return Action::make('microsoftDisconnect')
            ->label('Verbinding verbreken')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (Source $record): bool => self::isConnected($record))
            ->requiresConfirmation()
            ->modalDescription('PHP Zoeker vergeet de verbinding met Microsoft 365 en zet de bron uit. Daarna is opnieuw inloggen nodig.')
            ->action(function (Source $record): void {
                app(MicrosoftLogin::class)->disconnect($record);
                Notification::make()->success()->title('Verbinding verbroken')->send();
            });
    }

    public static function isConnected(Source $source): bool
    {
        return filled($source->config['refresh_token'] ?? null);
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
