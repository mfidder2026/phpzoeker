<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Ai\Agents\CvGroundingJudge;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiRunStatus;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Domain\Candidates\Data\CandidateProfileSchema;
use App\Domain\Offers\Actions\CreateOffer;
use App\Domain\Offers\Actions\GenerateTailoredCv;
use App\Domain\Offers\Actions\OfferWorkflow;
use App\Domain\Offers\Actions\PrepareOfferMail;
use App\Domain\Offers\Actions\RequestConsent;
use App\Domain\Offers\Enums\ConsentStatus;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Jobs\GenerateTailoredCvJob;
use App\Domain\Offers\Jobs\PrepareOfferMailJob;
use App\Domain\Offers\Jobs\SendOutboundEmailJob;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OutboundEmail;
use App\Domain\Offers\Models\TailoredCv;
use App\Domain\Offers\Support\CvDiff;
use App\Domain\Offers\Support\Recipients;
use App\Filament\Resources\Candidates\Schemas\MasterProfileEditor;
use App\Filament\Resources\Offers\OfferActions;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Support\Access;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Wizard "Aanbieden" (§11.2): 1 Controle → 2 CV → 3 Mail → 4 Versturen. De stap volgt uit de
 * status van de aanbieding; elke stap is een menselijke goedkeuring.
 *
 * @property Offer $record
 */
class OfferWizard extends Page
{
    use InteractsWithRecord;

    protected static string $resource = OfferResource::class;

    protected string $view = 'filament.offers.wizard';

    public function mount(int|string $record): void
    {
        $this->record = Offer::query()->findOrFail($record);

        abort_unless(Access::user()?->can('view', $this->record) ?? false, 403);
    }

    public function getTitle(): string
    {
        return 'Aanbieden: '.$this->record->candidate->name;
    }

    public function getSubheading(): string
    {
        return $this->record->assignment->title.' · via '.$this->record->viaName().' · '.$this->record->status->getLabel();
    }

    public function step(): int
    {
        return match ($this->record->status) {
            OfferStatus::Concept => $this->record->latestCv === null ? 1 : 2,
            OfferStatus::CvInReview => 2,
            OfferStatus::MailInReview => 3,
            OfferStatus::WachtOpKandidaat, OfferStatus::KlaarVoorVerzending => 4,
            default => 5,
        };
    }

    public function hasRunningTask(): bool
    {
        return AiRun::query()
            ->where('subject_type', 'assignment')
            ->where('subject_id', $this->record->assignment_id)
            ->whereIn('status', [AiRunStatus::Queued->value, AiRunStatus::Running->value])
            ->where('created_at', '>=', now()->subHour())
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $this->record->refresh()->load(['candidate.activeProfile', 'assignment', 'match', 'latestCv', 'organization', 'contact', 'assignmentSource']);

        return [
            'offer' => $this->record,
            'step' => $this->step(),
            'cv' => $this->record->latestCv,
            'email' => $this->record->offerEmail(),
            'warnings' => app(CreateOffer::class)->similarOfferWarnings($this->record->assignment, $this->record->candidate),
            'running' => $this->hasRunningTask(),
        ];
    }

    public function settingsAction(): Action
    {
        return Action::make('settings')
            ->label('Route, kanaal en CV-opties')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->visible(fn (): bool => Access::isStaff() && in_array($this->record->status, [OfferStatus::Concept, OfferStatus::CvInReview], true))
            ->fillForm(fn (): array => [
                'assignment_source_id' => $this->record->assignment_source_id,
                'channel' => $this->record->channel->value,
                'offered_rate' => $this->record->offered_rate,
                ...Arr::only((array) $this->record->cv_options, ['contact_line', 'show_birth_date', 'show_city', 'show_driving_license', 'show_nationality', 'show_availability']),
            ])
            ->schema([
                Select::make('assignment_source_id')->label('Route (bron/intermediair)')
                    ->options(fn (): array => $this->record->assignment->sources()->with('source')->get()->mapWithKeys(fn ($source): array => [
                        $source->id => collect([$source->intermediary_name ?? $source->source->name, $source->contact_email])->filter()->implode(' · '),
                    ])->all()),
                Select::make('channel')->label('Kanaal')->required()->options([OfferChannel::Email->value => 'Mail', OfferChannel::Platform->value => 'Platformindiening']),
                TextInput::make('offered_rate')->label('Aangeboden tarief (€ per uur, excl. btw)')->numeric()->minValue(0)->required(),
                Section::make('Privacy-opties in het CV (§12.6)')->columns(2)->schema([
                    Select::make('contact_line')->label('Contactregel')->options(['fremen' => 'Fremen-contact (accountmanager)', 'candidate' => 'Contactgegevens van de kandidaat'])->required(),
                    Toggle::make('show_city')->label('Woonplaats tonen'),
                    Toggle::make('show_driving_license')->label('Rijbewijs tonen'),
                    Toggle::make('show_nationality')->label('Nationaliteit tonen'),
                    Toggle::make('show_availability')->label('Beschikbaarheid tonen'),
                    Toggle::make('show_birth_date')->label('Geboortedatum tonen')->helperText('Alleen als de aanvraag er expliciet om vraagt (R4).'),
                ]),
            ])
            ->action(function (array $data): void {
                $route = filled($data['assignment_source_id'] ?? null) ? $this->record->assignment->sources()->find((int) $data['assignment_source_id']) : null;

                $this->record->forceFill([
                    'assignment_source_id' => $route?->id,
                    'source_id' => $route?->source_id,
                    'organization_id' => $route->organization_id ?? $this->record->organization_id,
                    'contact_id' => $route->contact_id ?? $this->record->contact_id,
                    'channel' => OfferChannel::from($data['channel']),
                    'offered_rate' => $data['offered_rate'],
                    'cv_options' => Arr::only($data, ['contact_line', 'show_birth_date', 'show_city', 'show_driving_license', 'show_nationality', 'show_availability']),
                ])->save();

                Notification::make()->success()->title('Opgeslagen')->send();
            });
    }

    public function generateCvAction(): Action
    {
        return Action::make('generateCv')
            ->label(fn (): string => $this->record->latestCv === null ? 'CV herschrijven' : 'Opnieuw genereren')
            ->icon(Heroicon::OutlinedSparkles)
            ->visible(fn (): bool => Access::isStaff() && in_array($this->record->status, [OfferStatus::Concept, OfferStatus::CvInReview], true))
            ->requiresConfirmation(fn (): bool => $this->record->latestCv !== null)
            ->modalDescription('Maakt een nieuwe versie van het CV op basis van het master-profiel. Dit kost een AI-aanroep.')
            ->action(function (): void {
                if ($this->record->candidate->activeProfile === null) {
                    Notification::make()->danger()->title($this->record->candidate->name.' heeft nog geen goedgekeurd master-profiel.')->send();

                    return;
                }

                $run = GenerateTailoredCv::queueRun($this->record, Access::user());
                GenerateTailoredCvJob::dispatch($this->record->id, $run->id(), Access::user()?->id);

                $this->dispatch('ai-run-started', runId: $run->id());
            });
    }

    public function editCvAction(): Action
    {
        return Action::make('editCv')
            ->label('Bewerken')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->slideOver()
            ->modalWidth('5xl')
            ->visible(fn (): bool => Access::isStaff() && $this->record->latestCv !== null && ! $this->record->latestCv->isApproved())
            ->modalDescription('Je wijzigingen worden een nieuwe CV-versie. Daarna draait de grounding-check opnieuw.')
            ->fillForm(fn (): array => ['data' => $this->record->latestCv->content ?? []])
            ->schema(fn (): array => MasterProfileEditor::components([
                Tab::make('Aansluiting op de opdracht')->schema([
                    Repeater::make('data.aansluiting_op_opdracht')->hiddenLabel()->addActionLabel('Eis toevoegen')->schema([
                        TextInput::make('eis_id')->label('Eis')->required(),
                        TextInput::make('eis')->label('Tekst van de eis'),
                        Textarea::make('invulling')->label('Invulling')->rows(2)->required(),
                    ])->columns(3),
                ]),
            ]))
            ->action(function (array $data): void {
                $previous = $this->record->latestCv ?? abort(404);
                $content = [...$previous->content, ...CandidateProfileSchema::empty(), ...(array) $data['data']];
                $errors = CandidateProfileSchema::validate($content);

                if ($errors !== []) {
                    Notification::make()->danger()->title('Het CV is nog niet geldig')->body(implode("\n", array_slice($errors, 0, 5)))->send();

                    return;
                }

                $cv = $this->record->tailoredCvs()->create([
                    ...Arr::only($previous->attributesToArray(), ['language', 'profile_version', 'changes', 'model', 'prompt_version']),
                    'version' => $previous->version + 1,
                    'content' => $content,
                    'edited' => true,
                    'diff_summary' => CvDiff::summarize($this->record->candidate->activeProfile->data ?? [], $content),
                    'grounding_passed' => false,
                ]);

                $this->regroundAndRender($cv);
            });
    }

    private function regroundAndRender(TailoredCv $cv): void
    {
        $action = app(GenerateTailoredCv::class);
        $run = RunTracker::start(
            task: AiTask::Grounding,
            agent: CvGroundingJudge::class,
            subject: $this->record->assignment,
            startedBy: Access::user(),
            steps: [['key' => 'grounding', 'label' => 'Grounding-check', 'weight' => 100]],
            subjectLabel: 'Grounding-check — '.$this->record->candidate->name.' CV v'.$cv->version,
        );

        $report = $action->regroundEdited($this->record, $cv->content, $run);
        $cv->forceFill(['grounding_report' => $report, 'grounding_passed' => $report['passed']])->save();
        $action->renderFiles($cv);

        Notification::make()->success()->title("CV v{$cv->version} opgeslagen")->body($report['passed'] ? 'De grounding-check is gehaald.' : 'De grounding-check is niet gehaald; zie het rapport.')->send();
    }

    public function approveCvAction(): Action
    {
        return Action::make('approveCv')
            ->label('CV goedkeuren')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (): bool => Access::isStaff() && $this->record->latestCv !== null && ! $this->record->latestCv->isApproved())
            ->disabled(fn (): bool => ! ($this->record->latestCv->grounding_passed ?? false))
            ->tooltip(fn (): ?string => ($this->record->latestCv->grounding_passed ?? false) ? null : 'Goedkeuren kan pas als de grounding-check is gehaald.')
            ->requiresConfirmation()
            ->action(fn () => OfferActions::run(fn () => app(OfferWorkflow::class)->approveCv($this->record->latestCv ?? abort(404), OfferActions::user()), 'CV goedgekeurd'));
    }

    public function prepareMailAction(): Action
    {
        return Action::make('prepareMail')
            ->label(fn (): string => $this->record->offerEmail() === null ? ($this->record->channel === OfferChannel::Platform ? 'Indieningspakket maken' : 'Mailtekst maken') : 'Opnieuw maken')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (): bool => Access::isStaff() && ($this->record->latestCv?->isApproved() ?? false) && in_array($this->record->status, [OfferStatus::CvInReview, OfferStatus::MailInReview], true))
            ->requiresConfirmation(fn (): bool => $this->record->offerEmail() !== null)
            ->modalDescription('Maakt een nieuw concept; het huidige concept wordt vervangen.')
            ->action(function (): void {
                $run = PrepareOfferMail::queueRun($this->record, Access::user());
                PrepareOfferMailJob::dispatch($this->record->id, $run->id(), Access::user()?->id);

                $this->dispatch('ai-run-started', runId: $run->id());
            });
    }

    public function editMailAction(): Action
    {
        return Action::make('editMail')
            ->label('Mail bewerken')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->slideOver()
            ->modalWidth('3xl')
            ->visible(fn (): bool => Access::isStaff() && ($this->record->offerEmail()?->isEditable() ?? false))
            ->fillForm(function (): array {
                $email = $this->record->offerEmail() ?? abort(404);

                return ['to' => $email->to, 'cc' => $email->cc ?? [], 'bcc' => $email->bcc ?? [], 'subject' => $email->subject, 'body_text' => $email->body_text, 'manual_confirmed' => false];
            })
            ->schema(fn (): array => [
                TagsInput::make('to')->label('Aan')->required()->nestedRecursiveRules(['email'])
                    ->suggestions(array_keys(app(Recipients::class)->suggestions($this->record)))
                    ->helperText('Alleen adressen uit de opdrachttekst, de bron of bekende contactpersonen worden zonder bevestiging geaccepteerd (R5).'),
                Checkbox::make('manual_confirmed')->label('Ik heb een handmatig ingevoerd adres zelf gecontroleerd'),
                TagsInput::make('cc')->label('CC')->nestedRecursiveRules(['email']),
                TagsInput::make('bcc')->label('BCC')->nestedRecursiveRules(['email']),
                TextInput::make('subject')->label('Onderwerp')->required()->maxLength(250),
                Textarea::make('body_text')->label('Tekst')->rows(18)->required()
                    ->helperText('Als je de tekst wijzigt, wordt de HTML-versie opnieuw opgebouwd uit deze tekst.'),
            ])
            ->action(function (array $data): void {
                $email = $this->record->offerEmail() ?? abort(404);
                OfferActions::run(fn () => app(OfferWorkflow::class)->updateMail($email, $data), 'Mail opgeslagen');
            });
    }

    public function approveMailAction(): Action
    {
        return Action::make('approveMail')
            ->label('Mail goedkeuren')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (): bool => Access::isStaff() && ($this->record->offerEmail()?->isEditable() ?? false))
            ->requiresConfirmation()
            ->modalDescription(fn (): string => $this->record->consent_status === ConsentStatus::Gevraagd
                ? 'Na goedkeuring krijgt '.$this->record->candidate->name.' een mail met een akkoordlink (72 uur geldig).'
                : 'Daarna kun je de mail versturen.')
            ->action(function (): void {
                $email = $this->record->offerEmail() ?? abort(404);

                if (! OfferActions::run(fn () => app(OfferWorkflow::class)->approveMail($email, OfferActions::user()), 'Mail goedgekeurd')) {
                    return;
                }

                if ($this->record->refresh()->status === OfferStatus::WachtOpKandidaat) {
                    OfferActions::run(fn () => app(RequestConsent::class)->handle($this->record, OfferActions::user()), 'Akkoord gevraagd aan '.$this->record->candidate->name);
                }
            });
    }

    public function reopenMailAction(): Action
    {
        return Action::make('reopenMail')
            ->label('Mail weer bewerken')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (): bool => Access::isStaff() && $this->record->offerEmail()?->status === OutboundEmailStatus::Goedgekeurd)
            ->requiresConfirmation()
            ->action(fn () => OfferActions::run(fn () => app(OfferWorkflow::class)->reopenMail($this->record->offerEmail() ?? abort(404), OfferActions::user()), 'De mail is weer bewerkbaar'));
    }

    public function manualConsentAction(): Action
    {
        return Action::make('manualConsent')
            ->label('Akkoord handmatig registreren')
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('gray')
            ->visible(fn (): bool => Access::isStaff() && $this->record->status === OfferStatus::WachtOpKandidaat)
            ->schema([
                Select::make('status')->label('Uitkomst')->required()->options([ConsentStatus::Mondeling->value => 'Mondeling akkoord', ConsentStatus::Geweigerd->value => 'Niet akkoord']),
                TextInput::make('note')->label('Notitie')->required()->maxLength(250),
            ])
            ->action(fn (array $data) => OfferActions::run(fn () => app(OfferWorkflow::class)->registerConsent($this->record, ConsentStatus::from($data['status']), OfferActions::user(), $data['note']), 'Akkoord vastgelegd'));
    }

    public function sendAction(): Action
    {
        return Action::make('send')
            ->label(fn (): string => $this->record->channel === OfferChannel::Platform ? 'Interne mail versturen' : 'Versturen')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->visible(fn (): bool => (Access::user()?->can('send', $this->record) ?? false) && $this->record->status === OfferStatus::KlaarVoorVerzending && $this->record->offerEmail()?->status === OutboundEmailStatus::Goedgekeurd)
            ->requiresConfirmation()
            ->modalHeading('Versturen')
            ->modalDescription(function (): string {
                $email = $this->record->offerEmail();

                return 'Je verstuurt naar: '.implode(', ', $email->to ?? []).(filled($email?->cc) ? ' · CC: '.implode(', ', $email->cc) : '').(filled($email?->bcc) ? ' · BCC: '.implode(', ', $email->bcc) : '').'. Dit kan niet ongedaan worden gemaakt.';
            })
            ->modalSubmitActionLabel('Ja, versturen')
            ->action(function (): void {
                $email = $this->record->offerEmail() ?? abort(404);
                SendOutboundEmailJob::dispatch($email->id, OfferActions::user()->id);

                Notification::make()->success()->title('Wordt verstuurd')->body('Je krijgt een melding zodra de mail is verzonden.')->send();
            });
    }

    public function retrySendAction(): Action
    {
        return Action::make('retrySend')
            ->label('Opnieuw proberen')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('danger')
            ->visible(fn (): bool => Access::isStaff() && $this->record->offerEmail()?->status === OutboundEmailStatus::Fout)
            ->requiresConfirmation()
            ->modalDescription(fn (): string => 'De vorige poging mislukte: '.($this->record->offerEmail()->error ?? ''))
            ->action(function (): void {
                $email = $this->record->offerEmail() ?? abort(404);
                DB::transaction(fn () => $email->forceFill(['status' => OutboundEmailStatus::Goedgekeurd])->save());
                SendOutboundEmailJob::dispatch($email->id, OfferActions::user()->id);
            });
    }

    public function submittedAction(): Action
    {
        return Action::make('submitted')
            ->label('Ingediend')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (): bool => Access::isStaff() && $this->record->channel === OfferChannel::Platform && $this->record->status === OfferStatus::KlaarVoorVerzending && $this->record->offerEmail()?->status === OutboundEmailStatus::Verzonden)
            ->schema([TextInput::make('reference')->label('Extern referentienummer van het platform')->required()->maxLength(120)])
            ->action(function (array $data): void {
                if (OfferActions::run(fn () => app(OfferWorkflow::class)->markSubmitted($this->record, $data['reference'], OfferActions::user()), 'Gemarkeerd als ingediend')) {
                    $this->redirect(OfferResource::getUrl('view', ['record' => $this->record]));
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('register')->label('In het register')->color('gray')->icon(Heroicon::OutlinedQueueList)
                ->url(fn (): string => OfferResource::getUrl('view', ['record' => $this->record])),
            OfferActions::withdraw(),
        ];
    }

    public function emailPreview(?OutboundEmail $email): string
    {
        return $email === null ? '' : '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:;">'.$email->body_html;
    }
}
