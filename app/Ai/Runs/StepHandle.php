<?php

namespace App\Ai\Runs;

use App\Ai\Support\AiResponse;
use App\Ai\Support\SecretRedactor;

final readonly class StepHandle
{
    public function __construct(private TrackedRun $run, private string $key) {}

    public function begin(?string $detail = null): self
    {
        $this->run->throwIfCancelled();

        $this->run->updateStep($this->key, fn (array $step): array => [
            ...$step,
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'progress' => null,
        ]);
        $this->run->setCurrentStep($this->key);

        if ($detail !== null) {
            $this->run->detail($detail);
        }

        $this->run->broadcast(force: true);

        return $this;
    }

    /**
     * Gemeten voortgang binnen de stap, tussen 0 en 1 (bijv. gestreamde tokens / maximum).
     */
    public function progress(float $fraction): self
    {
        $fraction = max(0.0, min(1.0, $fraction));

        $this->run->updateStep($this->key, fn (array $step): array => [...$step, 'progress' => $fraction]);
        $this->run->broadcast();

        return $this;
    }

    public function complete(?AiResponse $usage = null): self
    {
        if ($usage !== null) {
            $this->run->recordUsage($usage);
        }

        $this->run->updateStep($this->key, fn (array $step): array => [
            ...$step,
            'status' => 'done',
            'progress' => 1.0,
            'finished_at' => now()->toIso8601String(),
        ]);
        $this->run->broadcast(force: true);

        return $this;
    }

    public function skip(): self
    {
        $this->run->updateStep($this->key, fn (array $step): array => [...$step, 'status' => 'skipped']);
        $this->run->broadcast(force: true);

        return $this;
    }

    public function failStep(string $message): self
    {
        $this->run->updateStep($this->key, fn (array $step): array => [
            ...$step,
            'status' => 'failed',
            'error' => SecretRedactor::redact($message),
            'finished_at' => now()->toIso8601String(),
        ]);
        $this->run->broadcast(force: true);

        return $this;
    }
}
