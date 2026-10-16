<?php

namespace App\Ai\Runs;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\Events\AiRunUpdated;
use App\Ai\Support\AiResponse;
use App\Ai\Support\SecretRedactor;
use Throwable;

/**
 * Een lopende AI-taak met stappen. Voortgang is echt: elke stap heeft een vast gewicht en
 * binnen een stap telt alleen gemeten voortgang mee (§15.4).
 */
final class TrackedRun
{
    private float $lastBroadcastAt = 0.0;

    public function __construct(public readonly AiRun $run, public readonly ?AiAgentProfile $profile) {}

    public function id(): string
    {
        return $this->run->id;
    }

    public function markRunning(): self
    {
        $this->run->forceFill([
            'status' => AiRunStatus::Running,
            'started_at' => $this->run->started_at ?? now(),
        ])->save();

        $this->broadcast(force: true);

        return $this;
    }

    public function step(string $key): StepHandle
    {
        return new StepHandle($this, $key);
    }

    public function detail(?string $detail): self
    {
        $this->run->forceFill(['current_detail' => $detail === null ? null : mb_substr($detail, 0, 250)])->save();
        $this->broadcast();

        return $this;
    }

    public function recordUsage(AiResponse $response, ?AiAgentProfile $pricing = null): self
    {
        $cost = ($pricing ?? $this->profile)?->estimateCost($response->model, $response->inputTokens, $response->outputTokens) ?? 0.0;

        $this->run->forceFill([
            'model' => $this->run->model ?? $response->model,
            'input_tokens' => $this->run->input_tokens + $response->inputTokens,
            'output_tokens' => $this->run->output_tokens + $response->outputTokens,
            'cost_eur' => round((float) $this->run->cost_eur + $cost, 5),
        ])->save();

        return $this;
    }

    public function isCancelRequested(): bool
    {
        return (bool) AiRun::query()->whereKey($this->run->getKey())->value('cancel_requested');
    }

    /**
     * @throws RunCancelledException
     */
    public function throwIfCancelled(): void
    {
        if ($this->isCancelRequested()) {
            throw new RunCancelledException;
        }
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function finish(?array $result = null): self
    {
        $steps = array_map(function (array $step): array {
            if ($step['status'] === 'pending' && ($step['optional'] ?? false)) {
                $step['status'] = 'skipped';
            }

            return $step;
        }, $this->run->steps ?? []);

        $this->run->forceFill([
            'status' => AiRunStatus::Succeeded,
            'steps' => $steps,
            'progress' => 100,
            'current_step' => null,
            'current_detail' => null,
            'result' => $result ?? $this->run->result,
            'finished_at' => now(),
        ])->save();

        $this->broadcast(force: true);

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function fail(Throwable|string $error, ?array $result = null): self
    {
        $code = $error instanceof AiProviderException ? $error->errorCode : 'error';
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $steps = array_map(function (array $step) use ($message): array {
            if ($step['status'] === 'running') {
                $step['status'] = 'failed';
                $step['error'] = SecretRedactor::redact($message);
                $step['finished_at'] = now()->toIso8601String();
            }

            return $step;
        }, $this->run->steps ?? []);

        $this->run->forceFill([
            'status' => AiRunStatus::Failed,
            'steps' => $steps,
            'error_code' => $code,
            'error_message' => SecretRedactor::redact(mb_substr($message, 0, 1000)),
            'result' => $result ?? $this->run->result,
            'current_detail' => null,
            'finished_at' => now(),
        ])->save();

        $this->broadcast(force: true);

        return $this;
    }

    public function cancel(): self
    {
        $this->run->forceFill([
            'status' => AiRunStatus::Cancelled,
            'current_detail' => null,
            'finished_at' => now(),
        ])->save();

        $this->broadcast(force: true);

        return $this;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    public function updateStep(string $key, callable $mutator): void
    {
        $steps = $this->run->steps ?? [];

        foreach ($steps as $index => $step) {
            if ($step['key'] === $key) {
                $steps[$index] = $mutator($step);
            }
        }

        /** @var list<array{key: string, label: string, weight: int, optional?: bool, status: string, started_at?: string|null, finished_at?: string|null, progress?: float|null, error?: string|null}> $steps */
        $this->run->forceFill([
            'steps' => $steps,
            'progress' => self::computeProgress($steps),
        ])->save();
    }

    public function setCurrentStep(?string $key): void
    {
        $this->run->forceFill(['current_step' => $key])->save();
    }

    /**
     * Gewogen voortgang over de stappen. Overgeslagen optionele stappen tellen niet mee.
     * Is de voortgang binnen de lopende stap onbekend, dan telt die stap als 0 (geen gok).
     *
     * @param  list<array<string, mixed>>  $steps
     */
    public static function computeProgress(array $steps): ?int
    {
        if ($steps === []) {
            return null;
        }

        $total = 0;
        $done = 0.0;

        foreach ($steps as $step) {
            if ($step['status'] === 'skipped') {
                continue;
            }

            $weight = (int) $step['weight'];
            $total += $weight;

            $done += match ($step['status']) {
                'done', 'failed' => $weight,
                'running' => $weight * (float) ($step['progress'] ?? 0),
                default => 0,
            };
        }

        return $total === 0 ? null : (int) floor($done / $total * 100);
    }

    /**
     * Stuurt `AiRunUpdated`; maximaal 2 keer per seconde per run, behalve bij statuswijzigingen.
     */
    public function broadcast(bool $force = false): void
    {
        $now = microtime(true);

        if (! $force && $now - $this->lastBroadcastAt < 0.5) {
            return;
        }

        $this->lastBroadcastAt = $now;

        event(new AiRunUpdated($this->run));
    }
}
