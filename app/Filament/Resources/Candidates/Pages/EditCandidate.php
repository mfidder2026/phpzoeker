<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Ai\Agents\CvImporter;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiRunStatus;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PromptRepository;
use App\Domain\Candidates\Actions\ApproveMasterProfile;
use App\Domain\Candidates\Jobs\ImportMasterCvJob;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Candidates\Models\CandidateProfile;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * @property Candidate $record
 */
class EditCandidate extends EditRecord
{
    protected static string $resource = CandidateResource::class;

    /**
     * AI-taak van "Aanmaken vanuit CV"; zolang die loopt, toont de pagina de voortgang.
     */
    #[Url]
    public ?string $importRun = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->importRun !== null) {
            $this->dispatch('ai-run-started', runId: $this->importRun);
        }
    }

    /**
     * Telefoon en geboortedatum staan in `$hidden` van het model (R4: nooit in array- of JSON-output) en
     * komen dus niet vanzelf in het formulier; zonder deze vulling zou opslaan ze leegmaken. De geboortedatum
     * gaat alleen naar de browser van staf, net als het veld.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['phone'] = $this->record->phone;

        if (Access::isStaff()) {
            $data['birth_date'] = $this->record->birth_date;
        }

        return $data;
    }

    /**
     * Als het CV is gelezen: formulier opnieuw vullen met de ingevulde gegevens.
     */
    #[On('ai-run-finished')]
    public function refreshAfterCvImport(): void
    {
        $run = $this->importRun !== null
            ? AiRun::query()->whereKey($this->importRun)->where('subject_type', $this->record->getMorphClass())->where('subject_id', $this->record->id)->first()
            : null;

        if ($run === null || $run->status->isOpen()) {
            return;
        }

        $this->importRun = null;
        $this->record->refresh();
        $this->fillForm();
        // Opnieuw gevuld = opgeslagen stand; anders vraagt de browser bij verlaten om bevestiging.
        $this->rememberData();

        $run->status === AiRunStatus::Succeeded
            ? Notification::make()->success()->title('CV gelezen')
                ->body('Controleer de gegevens en het master-profiel (onderaan), vul het tarief aan en klik bovenaan op "Master-profiel goedkeuren".')->send()
            : Notification::make()->danger()->title('CV lezen is niet gelukt')
                ->body('Vul de gegevens zelf in of probeer "CV importeren" opnieuw.')->send();
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): string
    {
        return $this->record->job_title.' · € '.number_format((float) $this->record->hourly_rate, 2, ',', '.').' · '.$this->record->hours_per_week.' uur · '
            .($this->record->isAvailableImmediately() ? 'per direct' : 'vanaf '.$this->record->available_from?->format('d-m-Y'));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->approveProfileAction(),
            $this->importCvAction(),
            DeleteAction::make()
                ->modalDescription('De kandidaat verdwijnt uit de lijst en wordt niet meer gematcht; matches zonder aanbieding worden verwijderd. Aanbiedingen blijven in het register. Terugzetten kan via het filter "Verwijderd".'),
            RestoreAction::make(),
        ];
    }

    /**
     * Nieuwste conceptversie van het master-profiel goedkeuren, bovenaan de pagina (FR-K4). De tabel
     * "Master-profiel" onderaan heeft dezelfde knop per versie.
     */
    public function approveProfileAction(): Action
    {
        return Action::make('approveProfile')
            ->label(fn (): string => 'Master-profiel goedkeuren (v'.$this->latestDraft()?->version.')')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (): bool => Access::isStaff() && $this->latestDraft() !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Master-profiel v'.$this->latestDraft()?->version.' goedkeuren')
            ->modalDescription('Na goedkeuring is deze versie de enige bron van waarheid voor getailorde CV\'s en wordt hij bevroren. Bekijk het profiel eerst onderaan bij "Master-profiel" en controleer of alle feiten kloppen.')
            ->modalSubmitActionLabel('Goedkeuren')
            ->action(function (): void {
                $profile = $this->latestDraft();

                if ($profile === null) {
                    return;
                }

                $errors = app(ApproveMasterProfile::class)->handle($profile, Access::user());

                if ($errors !== []) {
                    Notification::make()->danger()->title('Profiel is nog niet geldig')
                        ->body(implode("\n", array_slice($errors, 0, 6)))->persistent()->send();

                    return;
                }

                $this->record->refresh();
                Notification::make()->success()->title('Versie '.$profile->version.' is goedgekeurd en actief')->send();
            });
    }

    private function latestDraft(): ?CandidateProfile
    {
        $latest = $this->record->profiles()->first();

        return $latest !== null && ! $latest->isApproved() ? $latest : null;
    }

    /**
     * Master-CV uploaden (DOCX) en automatisch omzetten naar een conceptprofiel (FR-K4).
     */
    public function importCvAction(): Action
    {
        return Action::make('importCv')
            ->label('CV importeren')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => Access::isStaff())
            ->modalHeading('Master-CV importeren')
            ->modalDescription('Upload het Word-CV (.docx). De AI zet het om naar een conceptversie van het master-profiel; geboortedatum, telefoon en e-mail worden vooraf uit de tekst gehaald. Daarna review je en keur je het profiel goed.')
            ->modalSubmitActionLabel('Importeren')
            ->schema([
                FileUpload::make('file')->label('Word-bestand')
                    ->disk('local')->directory(fn (): string => 'candidates/'.$this->record->id.'/cv')->visibility('private')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                    ->preserveFilenames()
                    ->maxSize(10240)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $run = RunTracker::queue(
                    task: AiTask::Cv,
                    agent: CvImporter::class,
                    subject: $this->record,
                    startedBy: Access::user(),
                    steps: ImportMasterCvJob::STEPS,
                    subjectLabel: 'CV importeren — '.$this->record->name,
                    promptVersion: app(PromptRepository::class)->label(CvImporter::PROMPT),
                );

                ImportMasterCvJob::dispatch($run->id(), $this->record->id, (string) $data['file']);

                $this->dispatch('ai-run-started', runId: $run->id());
            });
    }
}
