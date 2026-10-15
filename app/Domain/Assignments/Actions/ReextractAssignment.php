<?php

namespace App\Domain\Assignments\Actions;

use App\Ai\Agents\AssignmentExtractor;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\PromptRepository;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Assignments\Support\AssignmentNormalizer;
use App\Domain\Geo\Geocoder;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * Extraheert een opdracht opnieuw (bijv. na een nieuwe promptversie, §10.6). Velden die een
 * mens heeft gecorrigeerd (`locked_fields`) blijven staan (§9.1).
 */
class ReextractAssignment
{
    public function __construct(
        private readonly AssignmentExtractor $extractor,
        private readonly AssignmentNormalizer $normalizer,
        private readonly Geocoder $geocoder,
        private readonly PromptRepository $prompts,
    ) {}

    public function handle(Assignment $assignment, ?User $user, ?string $runId = null): void
    {
        $run = $runId !== null ? RunTracker::resume($runId)->markRunning() : RunTracker::start(
            task: AiTask::Extract,
            agent: AssignmentExtractor::class,
            subject: $assignment,
            startedBy: $user,
            steps: self::steps(),
            subjectLabel: 'Opnieuw extraheren — '.Str::limit($assignment->title, 80),
            promptVersion: $this->prompts->label(AssignmentExtractor::PROMPT),
        );

        try {
            $step = $run->step('extract')->begin();
            $data = $this->extractor->extract($run, $assignment->title, (string) $assignment->description_text, userInitiated: true);
            $step->complete();

            $step = $run->step('process')->begin();
            $attributes = $this->normalizer->normalize($data, (string) $assignment->description_text);

            if ($attributes['city'] !== null && ($geo = $this->geocoder->geocode($attributes['city'])) !== null) {
                $attributes['lat'] = $geo['lat'];
                $attributes['lng'] = $geo['lng'];
                $attributes['province'] ??= $geo['province'];
            }

            $updates = collect($attributes)
                ->reject(fn (mixed $value, string $field): bool => $assignment->isFieldLocked($field))
                ->except(['title'])
                ->all();

            $assignment->fill([
                ...$updates,
                'extraction_model' => $run->run->refresh()->model,
                'extraction_prompt_version' => $this->prompts->label(AssignmentExtractor::PROMPT),
            ])->save();
            $step->complete();

            $run->finish(['assignment_id' => $assignment->id]);
        } catch (Throwable $exception) {
            $run->fail($exception);
        }
    }

    /**
     * @return list<array{key: string, label: string, weight: int}>
     */
    public static function steps(): array
    {
        return [
            ['key' => 'extract', 'label' => 'Eisen, wensen en kenmerken extraheren', 'weight' => 85],
            ['key' => 'process', 'label' => 'Normaliseren en geocoderen', 'weight' => 15],
        ];
    }
}
