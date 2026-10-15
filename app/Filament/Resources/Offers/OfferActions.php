<?php

namespace App\Filament\Resources\Offers;

use App\Domain\Offers\Actions\CreateFollowUpMail;
use App\Domain\Offers\Actions\OfferWorkflow;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Jobs\SendOutboundEmailJob;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Support\Recipients;
use App\Filament\Support\Access;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Acties op de detailpagina van het register (§11.5): status wijzigen, opvolgen, notitie,
 * reactiedatum aanpassen, reactie en intake vastleggen, intrekken.
 */
final class OfferActions
{
    /**
     * @var array<string, string>
     */
    public const REJECTION_REASONS = [
        'andere_kandidaat' => 'Andere kandidaat gekozen',
        'tarief' => 'Tarief te hoog',
        'ervaring' => 'Ervaring past niet',
        'beschikbaarheid' => 'Beschikbaarheid',
        'opdracht_vervallen' => 'Opdracht vervallen of ingevuld',
        'geen_reden' => 'Geen reden opgegeven',
        'overig' => 'Overig',
    ];

    public static function changeStatus(): Action
    {
        return Action::make('changeStatus')
            ->label('Status wijzigen')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (Offer $record): bool => Access::isStaff() && ! $record->status->isPreparing() && $record->status->transitions() !== [])
            ->schema(fn (Offer $record): array => [
                Select::make('status')->label('Nieuwe status')->required()->live()
                    ->options(collect($record->status->transitions())->mapWithKeys(fn (OfferStatus $status): array => [$status->value => $status->getLabel()])->all()),
                Select::make('rejection')->label('Reden van afwijzing')->options(self::REJECTION_REASONS)
                    ->visible(fn ($get): bool => $get('status') === OfferStatus::Afgewezen->value),
                TextInput::make('reason')->label('Toelichting (optioneel)')->maxLength(250),
            ])
            ->action(function (Offer $record, array $data): void {
                $reason = collect([self::REJECTION_REASONS[$data['rejection'] ?? ''] ?? null, $data['reason'] ?? null])->filter()->implode(': ');
                self::run(fn () => app(OfferWorkflow::class)->changeStatus($record, OfferStatus::from($data['status']), $reason ?: null, self::user()), 'Status gewijzigd');
            });
    }

    public static function followUp(): Action
    {
        return Action::make('followUp')
            ->label('Opvolgmail maken')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('warning')
            ->visible(fn (Offer $record): bool => Access::isStaff() && $record->status->isOpen() && $record->response_received_at === null && $record->channel->value === 'email')
            ->modalHeading('Opvolgmail')
            ->modalDescription('Concept aan dezelfde ontvangers en CC, als antwoord op de aanbiedingsmail. Controleer de tekst; versturen gebeurt pas na jouw bevestiging.')
            ->modalSubmitActionLabel('Goedkeuren en versturen')
            ->fillForm(function (Offer $record): array {
                $email = app(CreateFollowUpMail::class)->handle($record);

                return ['email_id' => $email->id, 'to' => $email->to, 'cc' => $email->cc ?? [], 'subject' => $email->subject, 'body_text' => $email->body_text];
            })
            ->schema([
                TextInput::make('email_id')->hidden()->dehydratedWhenHidden(),
                TagsInput::make('to')->label('Aan')->disabled()->dehydrated(),
                TagsInput::make('cc')->label('CC')->nestedRecursiveRules(['email']),
                TextInput::make('subject')->label('Onderwerp')->required(),
                Textarea::make('body_text')->label('Tekst')->rows(10)->required(),
            ])
            ->action(function (Offer $record, array $data): void {
                $email = $record->emails()->findOrFail((int) $data['email_id']);

                self::run(function () use ($email, $data): void {
                    $workflow = app(OfferWorkflow::class);
                    $workflow->updateMail($email, ['to' => $email->to, 'cc' => Recipients::dedupe($data['cc'] ?? []), 'bcc' => $email->bcc ?? [], 'subject' => $data['subject'], 'body_text' => $data['body_text'], 'manual_confirmed' => true]);
                    $workflow->approveMail($email, self::user());
                    SendOutboundEmailJob::dispatch($email->id, self::user()->id);
                }, 'Opvolgmail wordt verstuurd');
            });
    }

    public static function addNote(): Action
    {
        return Action::make('addNote')
            ->label('Notitie')
            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
            ->color('gray')
            ->visible(fn (): bool => Access::isStaff())
            ->schema([Textarea::make('text')->label('Notitie')->required()->rows(4)->maxLength(2000)])
            ->action(fn (Offer $record, array $data) => self::run(fn () => app(OfferWorkflow::class)->addNote($record, $data['text'], self::user()), 'Notitie toegevoegd'));
    }

    public static function adjustResponseTerm(): Action
    {
        return Action::make('adjustResponseTerm')
            ->label('Reactietermijn aanpassen')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (Offer $record): bool => Access::isStaff() && $record->status->isOpen())
            ->fillForm(fn (Offer $record): array => ['days' => $record->response_term_days])
            ->schema([
                TextInput::make('days')->label('Reactietermijn (werkdagen na verzending of deadline)')->numeric()->minValue(1)->maxValue(60)->required(),
                TextInput::make('reason')->label('Reden')->required()->maxLength(250),
            ])
            ->action(fn (Offer $record, array $data) => self::run(fn () => app(OfferWorkflow::class)->adjustResponseTerm($record, (int) $data['days'], $data['reason'], self::user()), 'Reactietermijn aangepast'));
    }

    public static function recordResponse(): Action
    {
        return Action::make('recordResponse')
            ->label('Reactie ontvangen')
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('gray')
            ->visible(fn (Offer $record): bool => Access::isStaff() && in_array($record->status, [OfferStatus::Verzonden, OfferStatus::GeenReactie], true))
            ->schema([Textarea::make('note')->label('Wat is er gezegd? (optioneel)')->rows(3)->maxLength(1000)])
            ->action(fn (Offer $record, array $data) => self::run(fn () => app(OfferWorkflow::class)->recordResponse($record, self::user(), $data['note'] ?? null), 'Reactie vastgelegd'));
    }

    public static function recordIntake(): Action
    {
        return Action::make('recordIntake')
            ->label('Intake vastleggen')
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('gray')
            ->visible(fn (Offer $record): bool => Access::isStaff() && in_array($record->status, [OfferStatus::Verzonden, OfferStatus::ReactieOntvangen, OfferStatus::GeenReactie, OfferStatus::IntakeGepland], true))
            ->schema([
                DateTimePicker::make('at')->label('Datum en tijd')->seconds(false)->required(),
                TextInput::make('location')->label('Locatie of Teams-link')->maxLength(500),
                TextInput::make('participants')->label('Deelnemers')->maxLength(500),
            ])
            ->action(fn (Offer $record, array $data) => self::run(fn () => app(OfferWorkflow::class)->recordIntake($record, $data, self::user()), 'Intake vastgelegd'));
    }

    public static function withdraw(): Action
    {
        return Action::make('withdraw')
            ->label('Intrekken')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Offer $record): bool => Access::isStaff() && $record->status->canTransitionTo(OfferStatus::Ingetrokken))
            ->schema([TextInput::make('reason')->label('Reden')->required()->maxLength(250)])
            ->action(fn (Offer $record, array $data) => self::run(fn () => app(OfferWorkflow::class)->withdraw($record, $data['reason'], self::user()), 'Aanbieding ingetrokken'));
    }

    public static function user(): User
    {
        return Access::user() ?? abort(403);
    }

    /**
     * Voert een stap uit en toont een fout in gewone taal.
     */
    public static function run(callable $callback, string $success): bool
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return false;
        }

        Notification::make()->success()->title($success)->send();

        return true;
    }
}
