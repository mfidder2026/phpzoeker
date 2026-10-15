<?php

namespace App\Ai\Jobs;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Config\ConnectionTester;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\AiAgentSettings;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dagelijks 06:45 (FR-AI8, §18.5): stappen 1–3 van de verbindingstest op het actieve
 * AI-agentprofiel. Bij een fout krijgt de beheerder een melding; Instellingen krijgt een rode
 * badge (zie `SettingsCluster`).
 */
class AiConnectionHealthJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('ai');
    }

    public function handle(ConnectionTester $tester): void
    {
        $profile = AiAgentProfile::active();

        if ($profile === null) {
            return;
        }

        $run = RunTracker::queue(
            task: AiTask::ConnectionTest,
            agent: ConnectionTester::class,
            subject: $profile,
            steps: ConnectionTester::STEPS,
            subjectLabel: 'Dagelijkse verbindingstest — '.$profile->name,
            profile: $profile,
        );

        $attributes = [...$profile->only([
            'name', 'provider', 'base_url', 'extra_options', 'models', 'max_output_tokens', 'timeout_seconds',
            'max_concurrency', 'daily_budget_eur', 'prices', 'web_research_enabled', 'web_allowed_domains', 'web_test_url',
        ]), 'api_key' => $profile->api_key];

        (new RunConnectionTestJob($run->id(), $attributes, $profile->id, includeWeb: false))->handle($tester);

        $result = $profile->refresh()->last_test_result;

        if ($result !== null && ! ($result['passed'] ?? false)) {
            $failure = null;

            foreach ((array) ($result['steps'] ?? []) as $step) {
                if (is_array($step) && ! ($step['passed'] ?? true)) {
                    $failure = $step;

                    break;
                }
            }

            Notification::make()
                ->danger()
                ->title('AI-verbinding mislukt')
                ->body($profile->provider->getLabel().': '.($failure['message'] ?? 'onbekende fout'))
                ->actions([Action::make('instellingen')->label('Naar Instellingen → AI-agent')->url(AiAgentSettings::getUrl())])
                ->sendToDatabase(User::query()->whereHas('roles', fn ($query) => $query->where('name', Role::Beheerder->value))->get());
        }
    }
}
