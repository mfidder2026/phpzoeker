<?php

namespace Database\Seeders;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Providers\ProviderName;
use App\Ai\Runs\AiTask;
use Illuminate\Database\Seeder;

/**
 * Startwaarde voor het AI-agentprofiel uit .env (AI_BOOTSTRAP_*). Daarna beheert de beheerder
 * het profiel in Instellingen → AI-agent (ADR-08).
 */
class AiAgentProfileSeeder extends Seeder
{
    public function run(): void
    {
        if (AiAgentProfile::query()->exists()) {
            return;
        }

        $provider = ProviderName::tryFrom((string) config('phpzoeker.ai_bootstrap.provider')) ?? ProviderName::Ollama;
        $model = (string) config('phpzoeker.ai_bootstrap.model');

        $defaults = match ($provider) {
            ProviderName::Anthropic => [
                'extract' => 'claude-haiku-5-5', 'match' => 'claude-sonnet-5-5', 'research' => 'claude-sonnet-5-5',
                'cv' => 'claude-sonnet-5-5', 'grounding' => 'claude-sonnet-5-5', 'mail' => 'claude-sonnet-5-5',
            ],
            default => array_fill_keys(array_map(fn (AiTask $task): string => $task->value, AiTask::modelTasks()), $model),
        };

        if ($model !== '') {
            $defaults = array_fill_keys(array_keys($defaults), $model);
        }

        AiAgentProfile::query()->create([
            'name' => $provider->getLabel().' — productie',
            'provider' => $provider,
            'api_key' => config('phpzoeker.ai_bootstrap.api_key'),
            'base_url' => config('phpzoeker.ai_bootstrap.base_url') ?: $provider->defaultBaseUrl(),
            'models' => $defaults,
            'max_output_tokens' => collect(AiTask::modelTasks())
                ->mapWithKeys(fn (AiTask $task): array => [$task->value => $task->defaultMaxOutputTokens()])
                ->all(),
            'timeout_seconds' => 120,
            'max_concurrency' => 3,
            'daily_budget_eur' => 10,
            'prices' => ['*' => ['input' => 0, 'output' => 0]],
            'web_research_enabled' => true,
            'web_allowed_domains' => ['fremen.app'],
            'web_test_url' => null,
            // De verwerkersovereenkomst moet de beheerder zelf bevestigen; tot die tijd is het profiel niet actief.
            'dpa_confirmed_at' => null,
            'is_active' => false,
        ]);
    }
}
