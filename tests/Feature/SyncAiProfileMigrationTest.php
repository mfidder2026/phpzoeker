<?php

use App\Ai\Config\AiAgentProfile;

function runAiProfileSyncMigration(): void
{
    (require database_path('migrations/2026_10_19_090000_sync_ai_profile_with_bootstrap.php'))->up();
}

it('puts the bootstrap ollama settings on the existing profile without touching the dpa or activation', function () {
    $profile = aiProfile(['api_key' => 'oude-sleutel-0000', 'base_url' => 'https://oud.test', 'is_active' => false, 'dpa_confirmed_at' => null]);
    config(['phpzoeker.ai_bootstrap' => ['provider' => 'ollama', 'base_url' => 'https://ollama.com', 'api_key' => 'nieuwe-sleutel-1234', 'model' => 'glm-5.2']]);

    runAiProfileSyncMigration();

    $profile->refresh();

    expect($profile->api_key)->toBe('nieuwe-sleutel-1234')
        ->and($profile->base_url)->toBe('https://ollama.com')
        ->and(array_unique(array_values($profile->models)))->toBe(['glm-5.2'])
        ->and($profile->is_active)->toBeFalse()
        ->and($profile->dpa_confirmed_at)->toBeNull();
});

it('does nothing without a bootstrap api key or profile', function () {
    config(['phpzoeker.ai_bootstrap' => ['provider' => 'ollama', 'base_url' => null, 'api_key' => null, 'model' => null]]);
    $profile = aiProfile(['api_key' => 'blijft-staan-9999']);

    runAiProfileSyncMigration();

    expect($profile->refresh()->api_key)->toBe('blijft-staan-9999');

    AiAgentProfile::query()->delete();
    config(['phpzoeker.ai_bootstrap.api_key' => 'x']);
    runAiProfileSyncMigration();

    expect(AiAgentProfile::query()->count())->toBe(0);
});
