<?php

use App\Ai\Config\AiAgentProfile;
use App\Ai\Providers\ProviderName;
use App\Ai\Runs\AiTask;
use Illuminate\Database\Migrations\Migration;

/**
 * Eenmalig: zet provider, base-URL, API-sleutel en model uit AI_BOOTSTRAP_* op het bestaande
 * AI-profiel (D58). De seeder vult alleen een lege database; in productie stond het profiel al.
 * Verwerkersovereenkomst en actief/inactief blijven ongewijzigd: die bevestigt de beheerder zelf.
 */
return new class extends Migration
{
    public function up(): void
    {
        $config = (array) config('phpzoeker.ai_bootstrap');
        $provider = ProviderName::tryFrom((string) ($config['provider'] ?? ''));

        if ($provider === null || blank($config['api_key'] ?? null)) {
            return;
        }

        $profile = AiAgentProfile::active()
            ?? AiAgentProfile::query()->where('provider', $provider->value)->oldest('id')->first();

        if ($profile === null) {
            return;
        }

        $model = (string) ($config['model'] ?? '');
        $tasks = array_map(fn (AiTask $task): string => $task->value, AiTask::modelTasks());

        $profile->fill([
            'provider' => $provider,
            'base_url' => filled($config['base_url'] ?? null) ? $config['base_url'] : $provider->defaultBaseUrl(),
            'api_key' => $config['api_key'],
            'models' => $model !== '' ? array_fill_keys($tasks, $model) : $profile->models,
        ])->save();
    }

    public function down(): void
    {
        // Gegevens-migratie: de vorige sleutel is niet bewaard, dus er is niets terug te zetten.
    }
};
