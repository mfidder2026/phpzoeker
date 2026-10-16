<?php

namespace App\Filament\Resources\Matches;

use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Enums\Recommendation;
use App\Domain\Matching\Jobs\MatchAssignmentJob;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Offers\Actions\CreateOffer;
use App\Domain\Offers\Exceptions\DuplicateOfferException;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Acties op matches: shortlist, afwijzen, feedback (FR-M6), interesse van de kandidaat (§5),
 * opnieuw beoordelen (§10.6) en een knock-out overrulen met reden (§10.2).
 */
final class MatchActions
{
    /**
     * @var array<string, string>
     */
    public const FEEDBACK_REASONS = [
        'goede_match' => 'Klopt, goede match',
        'eisen' => 'Eisen verkeerd beoordeeld',
        'techniek' => 'Technologie past niet',
        'niveau' => 'Niveau past niet',
        'tarief' => 'Tarief past niet',
        'locatie' => 'Locatie of reistijd',
        'opdrachtgever' => 'Opdrachtgever of sector past niet',
        'overig' => 'Overig',
    ];

    /**
     * @var array<string, string>
     */
    public const REJECT_REASONS = [
        'geen_match' => 'Inhoudelijk geen match',
        'tarief' => 'Tarief',
        'beschikbaarheid' => 'Beschikbaarheid',
        'locatie' => 'Locatie of reistijd',
        'kandidaat' => 'Kandidaat heeft geen interesse',
        'al_aangeboden' => 'Al via een andere partij aangeboden',
        'overig' => 'Overig',
    ];

    public static function shortlist(): Action
    {
        return Action::make('shortlist')
            ->label('Op shortlist')
            ->icon(Heroicon::OutlinedStar)
            ->color('warning')
            ->visible(fn (CandidateMatch $record): bool => Access::isStaff() && in_array($record->status, [MatchStatus::Nieuw, MatchStatus::Bekeken, MatchStatus::Afgewezen], true))
            ->action(function (CandidateMatch $record): void {
                self::toShortlist($record);
                Notification::make()->success()->title('Op de shortlist gezet')->send();
            });
    }

    public static function shortlistBulk(): BulkAction
    {
        return BulkAction::make('shortlistBulk')
            ->label('Op shortlist')
            ->icon(Heroicon::OutlinedStar)
            ->visible(fn (): bool => Access::isStaff())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                foreach ($records as $record) {
                    if ($record instanceof CandidateMatch) {
                        self::toShortlist($record);
                    }
                }
                Notification::make()->success()->title($records->count().' matches op de shortlist')->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Afwijzen')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(fn (CandidateMatch $record): bool => Access::isStaff() && ! in_array($record->status, [MatchStatus::Afgewezen, MatchStatus::Aangeboden], true))
            ->schema(self::rejectSchema())
            ->action(function (CandidateMatch $record, array $data): void {
                self::markRejected($record, $data);
                Notification::make()->success()->title('Match afgewezen')->send();
            });
    }

    public static function rejectBulk(): BulkAction
    {
        return BulkAction::make('rejectBulk')
            ->label('Afwijzen')
            ->icon(Heroicon::OutlinedXMark)
            ->visible(fn (): bool => Access::isStaff())
            ->schema(self::rejectSchema())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                foreach ($records as $record) {
                    if ($record instanceof CandidateMatch) {
                        self::markRejected($record, $data);
                    }
                }
                Notification::make()->success()->title($records->count().' matches afgewezen')->send();
            });
    }

    public static function feedback(string $direction): Action
    {
        $up = $direction === CandidateMatch::FEEDBACK_UP;

        return Action::make($up ? 'feedbackUp' : 'feedbackDown')
            ->label($up ? '👍' : '👎')
            ->tooltip($up ? 'Goede match' : 'Geen goede match')
            ->color(fn (CandidateMatch $record): string => $record->feedback === $direction ? ($up ? 'success' : 'danger') : 'gray')
            ->visible(fn (): bool => Access::isStaff())
            ->modalHeading($up ? 'Goede match' : 'Geen goede match')
            ->modalDescription('Feedback wordt gebruikt om de gewichten later bij te sturen.')
            ->schema([
                Select::make('reason')->label('Reden')->options(self::FEEDBACK_REASONS)->default($up ? 'goede_match' : null)->required(),
                TextInput::make('note')->label('Toelichting (optioneel)')->maxLength(200),
            ])
            ->action(function (CandidateMatch $record, array $data) use ($direction): void {
                $record->forceFill([
                    'feedback' => $direction,
                    'feedback_reason' => self::reasonText(self::FEEDBACK_REASONS, $data),
                    'feedback_by' => Access::user()?->id,
                    'feedback_at' => now(),
                ])->save();

                Notification::make()->success()->title('Feedback opgeslagen')->send();
            });
    }

    /**
     * Interesse van de kandidaat zelf (§5: interesse aangeven met 👍/👎 en een reden).
     */
    public static function interest(string $direction): Action
    {
        $up = $direction === CandidateMatch::FEEDBACK_UP;

        return Action::make($up ? 'interestUp' : 'interestDown')
            ->label($up ? '👍 Interesse' : '👎 Geen interesse')
            ->color(fn (CandidateMatch $record): string => $record->candidate_interest === $direction ? ($up ? 'success' : 'danger') : 'gray')
            ->visible(fn (CandidateMatch $record): bool => Access::user()?->can('express', $record) ?? false)
            ->schema([
                Textarea::make('reason')->label('Reden (optioneel)')->rows(2)->maxLength(300),
            ])
            ->action(function (CandidateMatch $record, array $data) use ($direction): void {
                $record->forceFill(['candidate_interest' => $direction, 'candidate_interest_reason' => $data['reason'] ?? null])->save();

                Notification::make()->success()->title('Dank je, je reactie is opgeslagen')->send();
            });
    }

    public static function reassess(): Action
    {
        return Action::make('reassess')
            ->label('Opnieuw beoordelen')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => Access::isStaff())
            ->requiresConfirmation()
            ->modalDescription('Doet een nieuwe AI-beoordeling met de actieve promptversie en het huidige master-profiel. Dit kost een AI-aanroep.')
            ->action(function (CandidateMatch $record): void {
                MatchAssignmentJob::dispatch($record->assignment_id, $record->candidate_id, assess: true, force: true, startedBy: Access::user()?->id);

                Notification::make()->success()->title('Beoordeling gestart')->body('De voortgang staat bij AI-activiteit.')->send();
            });
    }

    public static function overrideKnockout(): Action
    {
        return Action::make('overrideKnockout')
            ->label('Knock-out overrulen')
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('danger')
            ->visible(fn (CandidateMatch $record): bool => Access::isStaff() && $record->knockout_failed && $record->knockout_override_reason === null)
            ->modalDescription('De beoordeling zegt dat de kandidaat een knock-out-eis niet haalt. Overrule alleen als je zeker weet dat de kandidaat er wel aan voldoet; de reden komt in het audit-log.')
            ->schema([
                Textarea::make('reason')->label('Reden')->required()->minLength(10)->maxLength(250)->rows(3),
            ])
            ->action(function (CandidateMatch $record, array $data): void {
                $record->forceFill([
                    'knockout_override_reason' => $data['reason'],
                    'knockout_overridden_by' => Access::user()?->id,
                ])->save();

                MatchAssignmentJob::dispatch($record->assignment_id, $record->candidate_id, assess: false);

                Notification::make()->success()->title('Knock-out overruled')->send();
            });
    }

    public static function offer(): Action
    {
        return Action::make('offer')
            ->label('Aanbieden')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (): bool => Access::isStaff())
            ->disabled(fn (CandidateMatch $record): bool => ! $record->hard_filter_passed || $record->candidate->active_profile_id === null)
            ->tooltip(fn (CandidateMatch $record): ?string => ! $record->hard_filter_passed ? 'Een harde filter is niet gehaald.' : ($record->candidate->active_profile_id === null ? 'Geen goedgekeurd master-profiel.' : null))
            ->requiresConfirmation()
            ->modalHidden(fn (CandidateMatch $record): bool => self::offerWarning($record) === null)
            ->modalHeading('Toch aanbieden?')
            ->modalDescription(fn (CandidateMatch $record): ?string => self::offerWarning($record))
            ->action(function (CandidateMatch $record): void {
                try {
                    $offer = app(CreateOffer::class)->handle($record->assignment, $record->candidate, Access::user(), $record);
                } catch (DuplicateOfferException $exception) {
                    $notification = Notification::make()->danger()->title($exception->getMessage());

                    if ($exception->existing !== null) {
                        $notification->actions([Action::make('bekijken')->label('Bestaande aanbieding')->url(OfferResource::getUrl('view', ['record' => $exception->existing]))]);
                    }

                    $notification->send();

                    return;
                }

                redirect(OfferResource::getUrl('wizard', ['record' => $offer]));
            });
    }

    /**
     * Waarschuwing voor "Aanbieden": alleen bij een niet-gehaalde knock-out-eis of het advies "niet aanbieden".
     */
    public static function offerWarning(CandidateMatch $match): ?string
    {
        return match (true) {
            $match->isKnockoutBlocking() => 'Een knock-out-eis is niet gehaald. Weet je zeker dat je '.$match->candidate->name.' toch wilt aanbieden?',
            $match->recommendation === Recommendation::NietAanbieden => 'Het advies van de beoordeling is "niet aanbieden". Weet je zeker dat je '.$match->candidate->name.' toch wilt aanbieden?',
            default => null,
        };
    }

    public static function toShortlist(CandidateMatch $match): void
    {
        $match->setStatus(MatchStatus::Shortlist);

        if (in_array($match->assignment->status, [AssignmentStatus::Nieuw, AssignmentStatus::Gematcht], true)) {
            $match->assignment->forceFill(['status' => AssignmentStatus::Shortlist])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function markRejected(CandidateMatch $match, array $data): void
    {
        $match->forceFill([
            'status' => MatchStatus::Afgewezen,
            'status_changed_at' => now(),
            'rejection_reason' => self::reasonText(self::REJECT_REASONS, $data),
        ])->save();
    }

    /**
     * @return list<Select|TextInput>
     */
    private static function rejectSchema(): array
    {
        return [
            Select::make('reason')->label('Reden')->options(self::REJECT_REASONS)->required(),
            TextInput::make('note')->label('Toelichting (optioneel)')->maxLength(200),
        ];
    }

    /**
     * @param  array<string, string>  $reasons
     * @param  array<string, mixed>  $data
     */
    private static function reasonText(array $reasons, array $data): string
    {
        $reason = $reasons[$data['reason'] ?? ''] ?? (string) ($data['reason'] ?? '');

        return filled($data['note'] ?? null) ? $reason.': '.$data['note'] : $reason;
    }
}
