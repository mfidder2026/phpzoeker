<?php

use App\Ai\Config\AiAgentProfile;
use App\Ai\Providers\ProviderName;
use App\Domain\Candidates\Models\Candidate;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Gebruiker met één of meer rollen.
 */
function userWithRoles(Role ...$roles): User
{
    (new RoleSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole(array_map(fn (Role $role): string => $role->value, $roles));

    return $user;
}

function beheerder(): User
{
    return userWithRoles(Role::Beheerder, Role::Accountmanager);
}

function kandidaatUser(Candidate $candidate): User
{
    $user = userWithRoles(Role::Kandidaat);
    $user->update(['candidate_id' => $candidate->id]);

    return $user->refresh();
}

/**
 * Een actief AI-agentprofiel met een herkenbare testsleutel.
 *
 * @param  array<string, mixed>  $overrides
 */
function aiProfile(array $overrides = []): AiAgentProfile
{
    return AiAgentProfile::query()->create([
        'name' => 'Test — Ollama',
        'provider' => ProviderName::Ollama,
        'api_key' => 'test-secret-key-1234567890abcd',
        'base_url' => 'https://ollama.test',
        'models' => [
            'extract' => 'model-extract', 'match' => 'model-match', 'research' => 'model-research',
            'cv' => 'model-cv', 'grounding' => 'model-grounding', 'mail' => 'model-mail',
        ],
        'timeout_seconds' => 30,
        'max_concurrency' => 3,
        'daily_budget_eur' => 10,
        'prices' => ['*' => ['input' => 1, 'output' => 2]],
        'web_research_enabled' => false,
        'dpa_confirmed_at' => now(),
        'is_active' => true,
        ...$overrides,
    ]);
}

/**
 * Antwoord van de Ollama `/api/chat`-endpoint.
 *
 * @return array<string, mixed>
 */
function ollamaReply(string $content, int $in = 10, int $out = 5): array
{
    return [
        'model' => 'model-extract',
        'message' => ['role' => 'assistant', 'content' => $content],
        'done' => true,
        'prompt_eval_count' => $in,
        'eval_count' => $out,
    ];
}

/**
 * Geanonimiseerd master-profiel (Bijlage D) als fixture.
 *
 * @return array<string, mixed>
 */
function profileFixture(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/profiles/senior-developer.json'), true);
}
