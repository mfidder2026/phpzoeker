<?php

namespace App\Ai\Runs;

use App\Ai\Config\AiAgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Eén AI-taak. Voedt de AI-meter, de pagina AI-activiteit en de kostenbewaking (§15.4, R10).
 *
 * @property string $id
 * @property int|null $agent_profile_id
 * @property string $agent
 * @property AiTask $task
 * @property string|null $subject_label
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $started_by
 * @property AiRunStatus $status
 * @property list<array{key: string, label: string, weight: int, optional?: bool, status: string, started_at?: string|null, finished_at?: string|null, progress?: float|null, error?: string|null}>|null $steps
 * @property string|null $current_step
 * @property int|null $progress
 * @property string|null $current_detail
 * @property string|null $model
 * @property string|null $prompt_version
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_eur
 * @property array<string, mixed>|null $result
 * @property string|null $error_code
 * @property string|null $error_message
 * @property bool $cancel_requested
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read User|null $starter
 * @property-read AiAgentProfile|null $agentProfile
 */
class AiRun extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'status' => AiRunStatus::class,
            'steps' => 'array',
            'progress' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_eur' => 'decimal:5',
            'result' => 'array',
            'cancel_requested' => 'boolean',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return BelongsTo<AiAgentProfile, $this>
     */
    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AiAgentProfile::class, 'agent_profile_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<AiRun>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [AiRunStatus::Queued->value, AiRunStatus::Running->value]);
    }

    /**
     * @param  Builder<AiRun>  $query
     */
    public function scopeToday(Builder $query): void
    {
        $query->where('created_at', '>=', now()->startOfDay());
    }

    public function agentLabel(): string
    {
        return class_basename($this->agent);
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now());
    }
}
