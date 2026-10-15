<?php

namespace App\Ai\Runs;

use App\Ai\Config\AiAgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Startpunt voor elke AI-taak (R10, ADR-11). Maakt een `AiRun`-record aan zodat de AI-meter,
 * de kosten en de foutopsporing altijd kloppen.
 */
final class RunTracker
{
    /**
     * @param  list<array{key: string, label: string, weight: int, optional?: bool}>  $steps
     */
    public static function start(
        AiTask $task,
        string $agent,
        ?Model $subject = null,
        ?User $startedBy = null,
        array $steps = [],
        ?string $subjectLabel = null,
        ?AiAgentProfile $profile = null,
        ?string $promptVersion = null,
    ): TrackedRun {
        $run = self::queue($task, $agent, $subject, $startedBy, $steps, $subjectLabel, $profile, $promptVersion);
        $run->markRunning();

        return $run;
    }

    /**
     * Maakt een run aan in de wachtrij; de job roept later `resume()` + `markRunning()` aan.
     *
     * @param  list<array{key: string, label: string, weight: int, optional?: bool}>  $steps
     */
    public static function queue(
        AiTask $task,
        string $agent,
        ?Model $subject = null,
        ?User $startedBy = null,
        array $steps = [],
        ?string $subjectLabel = null,
        ?AiAgentProfile $profile = null,
        ?string $promptVersion = null,
    ): TrackedRun {
        $profile ??= AiAgentProfile::active();

        $run = new AiRun([
            'agent_profile_id' => $profile?->exists ? $profile->getKey() : null,
            'agent' => $agent,
            'task' => $task,
            'subject_label' => $subjectLabel,
            'started_by' => $startedBy?->getKey(),
            'status' => AiRunStatus::Queued,
            'steps' => array_map(fn (array $step): array => [
                'key' => $step['key'],
                'label' => $step['label'],
                'weight' => $step['weight'],
                'optional' => $step['optional'] ?? false,
                'status' => 'pending',
                'started_at' => null,
                'finished_at' => null,
                'progress' => null,
                'error' => null,
            ], $steps),
            'prompt_version' => $promptVersion,
            'queued_at' => now(),
        ]);

        if ($subject !== null) {
            $run->subject()->associate($subject);
        }

        $run->save();

        $tracked = new TrackedRun($run, $profile);
        $tracked->broadcast(force: true);

        return $tracked;
    }

    public static function resume(string $runId, ?AiAgentProfile $profile = null): TrackedRun
    {
        $run = AiRun::query()->findOrFail($runId);

        return new TrackedRun($run, $profile ?? $run->agentProfile ?? AiAgentProfile::active());
    }
}
