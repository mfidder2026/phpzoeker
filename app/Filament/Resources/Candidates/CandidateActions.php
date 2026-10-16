<?php

namespace App\Filament\Resources\Candidates;

use App\Ai\Agents\CvImporter;
use App\Ai\Config\AiAgentProfile;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PromptRepository;
use App\Domain\Candidates\Actions\CandidateFromCv;
use App\Domain\Candidates\Jobs\ImportMasterCvJob;
use App\Filament\Support\Access;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Acties rond kandidaten die op meer dan één pagina staan.
 */
final class CandidateActions
{
    /**
     * Nieuwe kandidaat vanuit een Word-CV: de AI leest naam, functie, senioriteit, woonplaats, talen,
     * zoekprofiel en het master-profiel; telefoon, e-mail, geboortedatum en pasfoto haalt de app zelf uit het CV.
     */
    public static function createFromCv(): Action
    {
        return Action::make('createFromCv')
            ->label('Aanmaken vanuit CV')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => Access::isStaff())
            ->modalHeading('Kandidaat aanmaken vanuit CV')
            ->modalDescription('Upload het Word-CV (.docx). De AI leest de kandidaatgegevens en zet het CV om naar een conceptversie van het master-profiel; telefoon, e-mail, geboortedatum en pasfoto haalt de app zelf uit het CV (die gaan niet naar de AI). Wat niet in het CV staat, zoals het tarief, vul je daarna zelf in.')
            ->modalSubmitActionLabel('CV lezen')
            ->schema([
                FileUpload::make('file')->label('Word-bestand')
                    ->disk('local')->directory('candidates/import')->visibility('private')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                    ->preserveFilenames()
                    ->maxSize(10240)
                    ->required(),
            ])
            ->action(function (array $data, CandidateFromCv $fromCv): void {
                if (AiAgentProfile::active() === null) {
                    Storage::disk('local')->delete((string) $data['file']);
                    Notification::make()->danger()->title('Geen actief AI-profiel')
                        ->body('Bevestig de verwerkersovereenkomst en zet het profiel aan in Instellingen → AI-agent; daarna kan het CV worden gelezen.')
                        ->persistent()->send();

                    return;
                }

                $candidate = $fromCv->placeholder();

                $path = 'candidates/'.$candidate->id.'/cv/'.basename((string) $data['file']);
                Storage::disk('local')->move((string) $data['file'], $path);

                $run = RunTracker::queue(
                    task: AiTask::Cv,
                    agent: CvImporter::class,
                    subject: $candidate,
                    startedBy: Access::user(),
                    steps: ImportMasterCvJob::STEPS,
                    subjectLabel: 'Kandidaat vanuit CV — '.basename($path),
                    promptVersion: app(PromptRepository::class)->label(CvImporter::PROMPT),
                );

                ImportMasterCvJob::dispatch($run->id(), $candidate->id, $path, fillCandidate: true);

                redirect(CandidateResource::getUrl('edit', ['record' => $candidate, 'importRun' => $run->id()]));
            });
    }
}
