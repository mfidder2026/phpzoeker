<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Ai\Agents\CvImporter;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PromptRepository;
use App\Domain\Candidates\Jobs\ImportMasterCvJob;
use App\Domain\Candidates\Models\Candidate;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Support\Access;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Candidate $record
 */
class EditCandidate extends EditRecord
{
    protected static string $resource = CandidateResource::class;

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
            $this->importCvAction(),
        ];
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
