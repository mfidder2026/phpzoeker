<?php

namespace App\Ai\Runs\Events;

use App\Ai\Runs\AiRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live update voor de AI-meter en het voortgangspaneel (§15.4). Gaat via Reverb naar het
 * privékanaal van de starter en het beheerderskanaal. Zonder websockets pollt de UI.
 */
class AiRunUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @var array{id: string, status: string, task: string, progress: int|null, current_step: string|null, current_detail: string|null}
     */
    public array $run;

    public ?int $startedBy;

    public function __construct(AiRun $run)
    {
        $this->startedBy = $run->started_by;
        $this->run = [
            'id' => $run->id,
            'status' => $run->status->value,
            'task' => $run->task->value,
            'progress' => $run->progress,
            'current_step' => $run->current_step,
            'current_detail' => $run->current_detail,
        ];
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('ai-runs.admins')];

        if ($this->startedBy !== null) {
            $channels[] = new PrivateChannel('ai-runs.user.'.$this->startedBy);
        }

        return $channels;
    }

    /**
     * @return array{run: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return ['run' => $this->run];
    }
}
