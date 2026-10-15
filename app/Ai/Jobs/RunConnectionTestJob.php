<?php

namespace App\Ai\Jobs;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Config\ConnectionTester;
use App\Ai\Runs\RunTracker;
use App\Ai\Support\SecretRedactor;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Voert de verbindingstest uit op de (eventueel nog niet opgeslagen) formulierwaarden.
 * De payload bevat de sleutel en is daarom versleuteld in de queue (R9).
 */
class RunConnectionTestJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $attributes  formulierwaarden van het agentprofiel
     */
    public function __construct(
        public string $runId,
        public array $attributes,
        public ?int $profileId = null,
        public bool $includeWeb = true,
    ) {
        $this->onQueue('ai');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function buildProfile(array $attributes): AiAgentProfile
    {
        $profile = new AiAgentProfile;
        $profile->forceFill(collect($attributes)->only([
            'name', 'provider', 'api_key', 'base_url', 'extra_options', 'models', 'max_output_tokens',
            'timeout_seconds', 'max_concurrency', 'daily_budget_eur', 'prices', 'web_research_enabled',
            'web_allowed_domains', 'web_test_url',
        ])->all());

        return $profile;
    }

    public function handle(ConnectionTester $tester): void
    {
        $profile = self::buildProfile($this->attributes);
        $run = RunTracker::resume($this->runId, $profile)->markRunning();

        try {
            $result = $tester->run($run, $this->includeWeb);

            if ($result['passed']) {
                $run->finish($result);
            } else {
                $firstFailure = collect($result['steps'])->firstWhere('passed', false);
                $run->fail('Verbindingstest mislukt: '.($firstFailure['label'] ?? '').' — '.($firstFailure['message'] ?? ''), $result);
            }
        } catch (Throwable $exception) {
            $run->fail($exception);
            $result = [
                'passed' => false,
                'tested_at' => now()->toIso8601String(),
                'steps' => [['key' => 'endpoint', 'label' => 'Endpoint en sleutel', 'passed' => false, 'message' => SecretRedactor::redact($exception->getMessage()), 'latency_ms' => null, 'model' => null]],
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_eur' => 0.0,
            ];
        }

        if ($this->profileId !== null) {
            AiAgentProfile::query()->whereKey($this->profileId)->update([
                'last_test_at' => now(),
                'last_test_result' => json_encode($result),
            ]);
        }

        $causer = $run->run->started_by !== null ? User::find($run->run->started_by) : null;

        activity('ai-agent')
            ->causedBy($causer)
            ->withProperties([
                'profiel' => $profile->name,
                'provider' => $profile->provider->value,
                'geslaagd' => $result['passed'],
                'run' => $this->runId,
            ])
            ->log($result['passed'] ? 'Verbindingstest geslaagd' : 'Verbindingstest mislukt');
    }
}
