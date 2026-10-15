<?php

use App\Ai\Config\AiAgentProfile;
use App\Filament\Clusters\Settings\Pages\AiAgentSettings;
use Illuminate\Support\Facades\DB;

it('stores the api key encrypted and remembers only the last four characters', function () {
    $profile = aiProfile(['api_key' => 'sk-very-secret-value-9876']);

    $raw = DB::table('ai_agent_profiles')->where('id', $profile->id)->value('api_key');

    expect($raw)->not->toContain('very-secret')
        ->and($profile->api_key_last4)->toBe('9876')
        ->and($profile->maskedApiKey())->toBe('••••••••9876')
        ->and($profile->refresh()->api_key)->toBe('sk-very-secret-value-9876');
});

it('never includes the api key in array or json output', function () {
    $profile = aiProfile(['api_key' => 'sk-very-secret-value-9876']);

    expect($profile->toJson())->not->toContain('very-secret')
        ->and($profile->toArray())->not->toHaveKey('api_key');
});

it('keeps the api key out of the audit log', function () {
    $profile = aiProfile(['api_key' => 'sk-very-secret-value-9876']);
    $profile->update(['api_key' => 'sk-replaced-secret-0000', 'name' => 'Nieuw']);

    $log = DB::table('activity_log')->where('log_name', 'ai-agent')->get()->toJson();

    expect($log)->not->toContain('very-secret')->not->toContain('replaced-secret');
});

it('cannot become active without a confirmed data processing agreement', function () {
    expect(fn () => aiProfile(['dpa_confirmed_at' => null, 'is_active' => true]))
        ->toThrow(DomainException::class);
});

it('keeps exactly one active profile', function () {
    $first = aiProfile();
    $second = aiProfile(['name' => 'Tweede']);

    expect($first->refresh()->is_active)->toBeFalse()
        ->and($second->refresh()->is_active)->toBeTrue()
        ->and(AiAgentProfile::active()?->is($second))->toBeTrue();
});

it('estimates the cost per million tokens', function () {
    $profile = aiProfile(['prices' => ['model-x' => ['input' => 3, 'output' => 15], '*' => ['input' => 1, 'output' => 1]]]);

    expect($profile->estimateCost('model-x', 1_000_000, 100_000))->toBe(4.5)
        ->and($profile->estimateCost('other', 500_000, 500_000))->toBe(1.0);
});

it('activates the profile from the settings page after confirming the agreement', function () {
    $profile = aiProfile(['dpa_confirmed_at' => null, 'is_active' => false]);
    $this->actingAs(beheerder());

    Livewire\Livewire::test(AiAgentSettings::class)
        ->set('data.dpa_confirmed', true)
        ->set('data.is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($profile->refresh()->is_active)->toBeTrue()
        ->and($profile->dpa_confirmed_at)->not->toBeNull()
        ->and($profile->api_key)->toBe('test-secret-key-1234567890abcd');
});
