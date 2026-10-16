<?php

namespace App\Domain\Candidates\Jobs;

use App\Ai\Agents\CandidateDetailsExtractor;
use App\Ai\Agents\CvImporter;
use App\Ai\Runs\RunCancelledException;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PiiScrubber;
use App\Ai\Support\PromptRepository;
use App\Domain\Candidates\Actions\CandidateFromCv;
use App\Domain\Candidates\Data\CandidateProfileSchema;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Candidates\Models\CandidateProfile;
use App\Domain\Documents\DocxPhotoExtractor;
use App\Domain\Documents\DocxTextExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * DOCX → tekst → `CvImporter` → concept-master-profiel (nieuwe versie, nog niet goedgekeurd).
 * Bij een nieuwe kandidaat vanuit het CV (`$fillCandidate`) vult `CandidateDetailsExtractor`
 * daarna ook de kandidaatgegevens in.
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
        ['key' => 'details', 'label' => 'Pasfoto en contactgegevens overnemen (lokaal)', 'weight' => 5],
        ['key' => 'scrub', 'label' => 'Persoonsgegevens afschermen', 'weight' => 5],
        ['key' => 'convert', 'label' => 'CV omzetten naar profiel', 'weight' => 70],
        ['key' => 'save', 'label' => 'Conceptversie opslaan', 'weight' => 15],
        ['key' => 'candidate', 'label' => 'Kandidaatgegevens invullen', 'weight' => 20, 'optional' => true],
    ];

    public function __construct(
        public string $runId,
        public int $candidateId,
        public string $path,
        public string $disk = 'local',
        public bool $fillCandidate = false,
    ) {
        $this->onQueue('ai');
    }

    public function handle(DocxTextExtractor $extractor, DocxPhotoExtractor $photos, PiiScrubber $scrubber, CvImporter $importer, PromptRepository $prompts, CandidateDetailsExtractor $detailsExtractor, CandidateFromCv $fromCv): void
    {
        $run = RunTracker::resume($this->runId)->markRunning();
        $candidate = Candidate::query()->findOrFail($this->candidateId);

        try {
            $step = $run->step('read')->begin(basename($this->path));
            $text = $extractor->extract(Storage::disk($this->disk)->path($this->path));
            $step->complete();

            $step = $run->step('details')->begin();
            $details = $this->takeOverDetails($candidate, $text, $photos);
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

            $filled = [];

            if ($this->fillCandidate) {
                $step = $run->step('candidate')->begin();
                $filled = $fromCv->fill($candidate->refresh(), $data, $detailsExtractor->extract($run, $scrubbed));
                $step->complete();
            }

            $run->finish([
                'candidate_profile_id' => $profile->id,
                'version' => $profile->version,
                'werkervaring' => count($data['werkervaring']),
                'projecten' => count($data['projecten']),
                'overgenomen' => $details,
                'ingevuld' => $filled,
            ]);
        } catch (RunCancelledException) {
            $run->cancel();
        } catch (Throwable $exception) {
            $run->fail($exception);
        }
    }

    /**
     * Neemt pasfoto, telefoon, e-mail en geboortedatum over uit het CV als die nog niet bij de kandidaat staan.
     * Dit gebeurt deterministisch in de app; deze gegevens gaan nooit naar het taalmodel (R4).
     *
     * @return list<string> welke gegevens zijn overgenomen (zonder de waarden)
     */
    private function takeOverDetails(Candidate $candidate, string $text, DocxPhotoExtractor $photos): array
    {
        $taken = [];
        $head = mb_substr($text, 0, 1500);

        if (blank($candidate->phone) && preg_match('/(?:\+31|0031|0)[\s\-.]?(?:\(0\))?\s?\d(?:[\s\-.]?\d){7,9}/u', $head, $match) === 1) {
            $candidate->phone = trim($match[0]);
            $taken[] = 'telefoon';
        }

        if (blank($candidate->email) && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $head, $match) === 1) {
            $candidate->email = $match[0];
            $taken[] = 'e-mail';
        }

        if (blank($candidate->birth_date) && ($birthDate = $this->birthDate($text)) !== null) {
            $candidate->birth_date = $birthDate;
            $taken[] = 'geboortedatum';
        }

        if (blank($candidate->photo_path) && ($photo = $photos->extract(Storage::disk($this->disk)->path($this->path))) !== null) {
            $path = 'candidates/photos/'.$candidate->slug.'.'.$photo['extension'];
            Storage::disk('local')->put($path, $photo['contents']);
            $candidate->photo_path = $path;
            $taken[] = 'pasfoto';
        }

        $candidate->save();

        return $taken;
    }

    /**
     * Geboortedatum van een regel die over geboorte gaat ("Geboortedatum: 14-03-1985", "geboren 14 maart 1985").
     */
    private function birthDate(string $text): ?string
    {
        if (preg_match('/^.*\b(?:geboren|geboortedatum|geb\.?\s*datum|date of birth|birth\s*date|born)\b.*$/imu', $text, $line) !== 1) {
            return null;
        }

        $months = ['januari' => 1, 'februari' => 2, 'maart' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6, 'juli' => 7, 'augustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'december' => 12,
            'january' => 1, 'february' => 2, 'march' => 3, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'october' => 10];

        if (preg_match('/\b(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{4})\b/u', $line[0], $match) === 1) {
            [$day, $month, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        } elseif (preg_match('/\b(\d{1,2})\s+('.implode('|', array_keys($months)).')\s+(\d{4})\b/iu', $line[0], $match) === 1) {
            [$day, $month, $year] = [(int) $match[1], $months[mb_strtolower($match[2])], (int) $match[3]];
        } else {
            return null;
        }

        if (! checkdate($month, $day, $year) || $year < 1930 || $year > (int) date('Y') - 15) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)?->format('Y-m-d');
    }
}
