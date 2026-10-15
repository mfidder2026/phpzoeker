<?php

namespace App\Domain\Sources\Actions;

use App\Ai\Agents\AssignmentExtractor;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PromptRepository;
use App\Domain\Assignments\Actions\ProcessRawPosting;
use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Jobs\ProcessRawPostingJob;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Formulier "Opdracht toevoegen" (§8.6, FR-B5): URL óf tekst plakken; loopt door dezelfde
 * pipeline. Geeft de AI-taak terug, zodat het voortgangspaneel kan openen (§15.4).
 */
class AddManualPosting
{
    public function __construct(private readonly PromptRepository $prompts) {}

    /**
     * @return array{posting: RawPosting, run_id: string}
     */
    public function handle(?string $url, ?string $text, ?string $title, ?User $user, ?Source $via = null): array
    {
        $url = filled($url) ? trim((string) $url) : null;
        $text = filled($text) ? trim((string) $text) : '';

        if ($url === null && $text === '') {
            throw new InvalidArgumentException('Vul een URL of de tekst van de opdracht in.');
        }

        if ($url !== null && ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('De URL is ongeldig.');
        }

        $source = $via ?? Source::query()->where('type', SourceType::Manual->value)->firstOrFail();
        $title = filled($title) ? trim((string) $title) : Str::limit(strtok($text, "\n") ?: ($url ?? 'Handmatige opdracht'), 120, '');

        $posting = RawPosting::query()->create([
            'source_id' => $source->id,
            'external_id' => 'manual:'.Str::uuid(),
            'url' => $url,
            'title' => mb_substr($title, 0, 255),
            'body_text' => $text,
            'meta' => ['kind' => $url !== null && $text === '' ? 'manual_url' : 'manual_text', 'added_by' => $user?->id],
            'content_hash' => RawPosting::hashContent($title, $text !== '' ? $text : $url),
            'published_at' => now(),
            'received_at' => now(),
            'status' => RawPostingStatus::Pending,
        ]);

        $run = RunTracker::queue(
            task: AiTask::Extract,
            agent: AssignmentExtractor::class,
            subject: $posting,
            startedBy: $user,
            steps: ProcessRawPosting::STEPS,
            subjectLabel: 'Opdracht toevoegen — '.Str::limit($posting->title, 80),
            promptVersion: $this->prompts->label(AssignmentExtractor::PROMPT),
        );

        ProcessRawPostingJob::dispatch($posting->id, $run->id());

        return ['posting' => $posting, 'run_id' => $run->id()];
    }
}
