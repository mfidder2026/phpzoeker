<?php

namespace App\Filament\Resources\Assignments;

use App\Ai\Agents\AssignmentExtractor;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PromptRepository;
use App\Domain\Assignments\Actions\ReextractAssignment;
use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Jobs\ReextractAssignmentJob;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Sources\Actions\AddManualPosting;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\Source;
use App\Filament\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Acties op opdrachten: toevoegen (§8.6), negeren met reden (§9.5), heropenen, opnieuw
 * extraheren (§10.6) en samenvoegen van een mogelijk duplicaat (§9.4).
 */
final class AssignmentActions
{
    /**
     * @var array<string, string>
     */
    public const IGNORE_REASONS = [
        'geen_match' => 'Past bij geen van de kandidaten',
        'tarief' => 'Tarief te laag',
        'locatie' => 'Locatie of reistijd',
        'uren' => 'Te weinig uren',
        'contractvorm' => 'Contractvorm past niet',
        'niet_relevant' => 'Geen softwareontwikkeling',
        'duplicaat' => 'Duplicaat',
        'overig' => 'Overig',
    ];

    public static function add(): Action
    {
        return Action::make('addAssignment')
            ->label('Opdracht toevoegen')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => Access::isStaff())
            ->modalHeading('Opdracht toevoegen')
            ->modalDescription('Plak de URL van een openbare opdrachtpagina óf de tekst van de aanvraag. De opdracht loopt door dezelfde verwerking als de andere bronnen.')
            ->modalSubmitActionLabel('Toevoegen en verwerken')
            ->schema([
                TextInput::make('url')->label('URL van de opdracht')->url()->requiredWithout('text'),
                Textarea::make('text')->label('Of: tekst van de opdracht')->rows(10)->requiredWithout('url'),
                TextInput::make('title')->label('Titel (optioneel)'),
                Select::make('source_id')->label('Ontvangen via (optioneel)')
                    ->options(fn (): array => Source::query()->where('type', '!=', SourceType::Manual->value)->pluck('name', 'id')->all())
                    ->helperText('Leeg = "Handmatig".'),
            ])
            ->action(function (array $data, Component $livewire): void {
                try {
                    $result = app(AddManualPosting::class)->handle(
                        url: $data['url'] ?? null,
                        text: $data['text'] ?? null,
                        title: $data['title'] ?? null,
                        user: Access::user(),
                        via: filled($data['source_id'] ?? null) ? Source::query()->whereKey($data['source_id'])->first() : null,
                    );
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                $livewire->dispatch('ai-run-started', runId: $result['run_id']);
            });
    }

    public static function ignore(): Action
    {
        return Action::make('ignore')
            ->label('Negeren')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('gray')
            ->visible(fn (Assignment $record): bool => Access::isStaff() && $record->isOpen())
            ->schema(self::ignoreSchema())
            ->action(function (Assignment $record, array $data): void {
                self::markIgnored($record, $data);
                Notification::make()->success()->title('Opdracht genegeerd')->send();
            });
    }

    public static function ignoreBulk(): BulkAction
    {
        return BulkAction::make('ignoreBulk')
            ->label('Negeren')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->visible(fn (): bool => Access::isStaff())
            ->schema(self::ignoreSchema())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                foreach ($records as $record) {
                    if ($record instanceof Assignment) {
                        self::markIgnored($record, $data);
                    }
                }
                Notification::make()->success()->title($records->count().' opdrachten genegeerd')->send();
            });
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('Heropenen')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Assignment $record): bool => Access::isStaff() && ! $record->isOpen())
            ->action(fn (Assignment $record) => $record->update(['status' => AssignmentStatus::Nieuw, 'ignore_reason' => null, 'closed_at' => null]));
    }

    public static function reextract(): Action
    {
        return Action::make('reextract')
            ->label('Opnieuw extraheren')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn (): bool => Access::isStaff())
            ->requiresConfirmation()
            ->modalDescription('De AI leest de opdrachttekst opnieuw met de actieve promptversie. Velden die je zelf hebt aangepast, blijven staan.')
            ->action(function (Assignment $record, Component $livewire): void {
                $run = RunTracker::queue(
                    task: AiTask::Extract,
                    agent: AssignmentExtractor::class,
                    subject: $record,
                    startedBy: Access::user(),
                    steps: ReextractAssignment::steps(),
                    subjectLabel: 'Opnieuw extraheren — '.Str::limit($record->title, 80),
                    promptVersion: app(PromptRepository::class)->label(AssignmentExtractor::PROMPT),
                );

                ReextractAssignmentJob::dispatch($record->id, $run->id(), Access::user()?->id);
                $livewire->dispatch('ai-run-started', runId: $run->id());
            });
    }

    /**
     * Mogelijk duplicaat (§9.4 regel 4): bronnen overzetten naar de oorspronkelijke opdracht.
     */
    public static function mergeDuplicate(): Action
    {
        return Action::make('mergeDuplicate')
            ->label('Samenvoegen met origineel')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('warning')
            ->visible(fn (Assignment $record): bool => Access::isStaff() && $record->possible_duplicate_of_id !== null && $record->isOpen())
            ->requiresConfirmation()
            ->modalDescription(fn (Assignment $record): string => 'Deze opdracht lijkt op "'.$record->possibleDuplicateOf?->title.'". De bronnen gaan naar die opdracht en deze opdracht wordt genegeerd als duplicaat.')
            ->action(function (Assignment $record): void {
                $original = $record->possibleDuplicateOf;

                if ($original === null) {
                    return;
                }

                $record->sources()->update(['assignment_id' => $original->id]);
                $record->rawPostings()->update(['assignment_id' => $original->id]);
                $original->update(['last_seen_at' => now()]);
                $record->update(['status' => AssignmentStatus::Genegeerd, 'ignore_reason' => 'Duplicaat van #'.$original->id, 'possible_duplicate_of_id' => null]);

                Notification::make()->success()->title('Samengevoegd met #'.$original->id)->send();
            });
    }

    public static function notDuplicate(): Action
    {
        return Action::make('notDuplicate')
            ->label('Geen duplicaat')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->visible(fn (Assignment $record): bool => Access::isStaff() && $record->possible_duplicate_of_id !== null)
            ->action(fn (Assignment $record) => $record->update(['possible_duplicate_of_id' => null]));
    }

    /**
     * @return list<Select|Textarea>
     */
    private static function ignoreSchema(): array
    {
        return [
            Select::make('reason')->label('Reden')->options(self::IGNORE_REASONS)->required()->live(),
            Textarea::make('note')->label('Toelichting')->rows(2)->required(fn (Get $get): bool => $get('reason') === 'overig'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function markIgnored(Assignment $record, array $data): void
    {
        $reason = self::IGNORE_REASONS[$data['reason']] ?? (string) $data['reason'];

        $record->update([
            'status' => AssignmentStatus::Genegeerd,
            'ignore_reason' => trim($reason.(filled($data['note'] ?? null) ? ': '.$data['note'] : '')),
        ]);
    }
}
