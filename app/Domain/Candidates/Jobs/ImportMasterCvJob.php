<?php

namespace App\Domain\Candidates\Jobs;

use App\Ai\Agents\CvImporter;
use App\Ai\Runs\RunCancelledException;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PiiScrubber;
use App\Ai\Support\PromptRepository;
use App\Domain\Candidates\Data\CandidateProfileSchema;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Candidates\Models\CandidateProfile;
use App\Domain\Documents\DocxTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * DOCX → tekst → `CvImporter` → concept-master-profiel (nieuwe versie, nog niet goedgekeurd).
 */
class ImportMasterCvJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @var list<array{key: string, label: string, weight: int, optional?: bool}>
     */
    public const STEPS = [
        ['key' => 'read', 'label' => 'Word-bestand lezen', 'weight' => 10],
        ['key' => 'scrub', 'label' => 'Persoonsgegevens afschermen', 'weight' => 5],
        ['key' => 'convert', 'label' => 'CV omzetten naar profiel', 'weight' => 70],
        ['key' => 'save', 'label' => 'Conceptversie opslaan', 'weight' => 15],
    ];

    public function __construct(
        public string $runId,
        public int $candidateId,
        public string $path,
        public string $disk = 'local',
    ) {
        $this->onQueue('ai');
    }

    public function handle(DocxTextExtractor $extractor, PiiScrubber $scrubber, CvImporter $importer, PromptRepository $prompts): void
    {
        $run = RunTracker::resume($this->runId)->markRunning();
        $candidate = Candidate::query()->findOrFail($this->candidateId);

        try {
            $step = $run->step('read')->begin(basename($this->path));
            $text = $extractor->extract(Storage::disk($this->disk)->path($this->path));
            $step->complete();

            $step = $run->step('scrub')->begin();
            $scrubbed = $scrubber->scrub($text, $candidate);
            $step->complete();

            $step = $run->step('convert')->begin();
            $data = $importer->import($run, $scrubbed);
            $step->complete();

            $step = $run->step('save')->begin();
            $profile = CandidateProfile::query()->create([
                'candidate_id' => $candidate->id,
                'version' => $candidate->nextProfileVersion(),
                'data' => $data,
                'source_file_path' => $this->path,
                'skills_vocabulary' => CandidateProfileSchema::skillsVocabulary($data),
                'model' => $run->run->refresh()->model,
                'prompt_version' => $prompts->label(CvImporter::PROMPT),
            ]);
            $step->complete();

            $run->finish([
                'candidate_profile_id' => $profile->id,
                'version' => $profile->version,
                'werkervaring' => count($data['werkervaring']),
                'projecten' => count($data['projecten']),
            ]);
        } catch (RunCancelledException) {
            $run->cancel();
        } catch (Throwable $exception) {
            $run->fail($exception);
        }
    }
}
