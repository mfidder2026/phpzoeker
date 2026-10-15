<?php

use App\Ai\Config\AiAgentProfile;
use App\Ai\Providers\ProviderName;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Documents\Contracts\PdfConverter;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

/**
 * Kandidaat met een goedgekeurd master-profiel uit een fixture en een zoekprofiel.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $search
 */
function matchableCandidate(string $fixture = 'senior-developer', array $attributes = [], array $search = []): Candidate
{
    $candidate = Candidate::factory()->create($attributes);
    $profile = $candidate->profiles()->create([
        'version' => 1,
        'data' => json_decode((string) file_get_contents(__DIR__."/Fixtures/profiles/{$fixture}.json"), true),
        'approved_at' => now(),
    ]);
    $candidate->searchProfile()->create([
        'roles' => ['PHP Developer'],
        'include_keywords' => ['php', 'laravel', 'developer'],
        'exclude_keywords' => ['stage'],
        'preferred_tech' => ['PHP', 'Laravel'],
        'excluded_tech' => ['COBOL'],
        'min_hours' => 24,
        'accepted_contract_types' => ['detachering', 'zzp', 'beide'],
        ...$search,
    ]);
    $candidate->forceFill(['active_profile_id' => $profile->id])->saveQuietly();

    return $candidate->refresh();
}

/**
 * Open, relevante opdracht met één knock-out-eis en één wens.
 *
 * @param  array<string, mixed>  $attributes
 */
function openAssignment(array $attributes = []): Assignment
{
    return Assignment::query()->create([
        'dedupe_group_id' => (string) Str::uuid(),
        'title' => 'Laravel Developer',
        'client_name' => 'Gemeente Voorbeeldstad',
        'summary' => 'Doorontwikkeling van een Laravel-platform.',
        'seniority' => 'senior',
        'contract_type' => 'beide',
        'hours_min' => 32,
        'hours_max' => 40,
        'start_asap' => true,
        'max_rate' => 100,
        'deadline_at' => now()->addDays(10),
        'technologies' => ['PHP', 'Laravel', 'PostgreSQL'],
        'requirements' => [
            ['id' => 'E1', 'tekst' => 'Minimaal 5 jaar ervaring met Laravel', 'knock_out' => true, 'categorie' => 'ervaring', 'min_jaren' => 5, 'technologie' => 'Laravel'],
        ],
        'wishes' => [
            ['id' => 'W1', 'tekst' => 'Ervaring met PostgreSQL', 'categorie' => 'techniek'],
        ],
        'is_relevant' => true,
        'status' => 'nieuw',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        ...$attributes,
    ]);
}

/**
 * JSON van een matchbeoordeling (Bijlage C) zoals het model die teruggeeft.
 *
 * @param  array<string, string>  $verdicts  id => oordeel
 */
function assessmentJson(array $verdicts = ['E1' => 'voldoet', 'W1' => 'voldoet'], string $recommendation = 'aanbieden'): string
{
    $rows = fn (string $prefix): array => collect($verdicts)
        ->filter(fn (string $verdict, string $id): bool => str_starts_with($id, $prefix))
        ->map(fn (string $verdict, string $id): array => ['id' => $id, 'oordeel' => $verdict, 'bewijs' => 'Volgens werkervaring[0].', 'bron_in_profiel' => 'werkervaring[0]'])
        ->values()->all();

    return (string) json_encode([
        'eisen' => $rows('E'),
        'wensen' => $rows('W'),
        'senioriteit_fit' => 'passend',
        'sterke_punten' => ['Ruime Laravel-ervaring'],
        'risicos' => [],
        'knock_out_gefaald' => [],
        'aanbeveling' => $recommendation,
        'toelichting' => 'Past goed bij de opdracht.',
    ]);
}

/**
 * Getailord CV zoals de CV-tailor het teruggeeft: het master-profiel uit de fixture plus de
 * sectie "Aansluiting op de opdracht".
 *
 * @param  array<string, mixed>  $overrides
 */
function tailoredCvJson(string $fixture = 'senior-developer', array $overrides = []): string
{
    $profile = json_decode((string) file_get_contents(__DIR__."/Fixtures/profiles/{$fixture}.json"), true);
    $profile['naam'] = 'De kandidaat';

    return (string) json_encode([
        ...$profile,
        'aansluiting_op_opdracht' => [['eis_id' => 'E1', 'eis' => 'Laravel', 'invulling' => 'Werkt sinds 2021 als senior developer met Laravel.']],
        'changes' => [['sectie' => 'profieltekst', 'wijziging' => 'Gericht op de opdracht']],
        ...$overrides,
    ]);
}

function groundingJson(array $claims = []): string
{
    return (string) json_encode(['unsupported_claims' => $claims]);
}

function mailWriterJson(): string
{
    return (string) json_encode([
        'intro' => 'Naar aanleiding van uw aanvraag bieden wij graag onze kandidaat aan.',
        'bullets' => ['Ruim tien jaar ervaring met PHP en Laravel.', 'Ervaring met PostgreSQL en Docker.', 'Werkt met Pest en CI/CD.'],
        'eis_antwoorden' => [['id' => 'E1', 'antwoord' => 'Sinds 2015 werkzaam met Laravel.']],
    ]);
}

/**
 * Een PDF-converter die zonder Word of Gotenberg een klein PDF-bestand schrijft.
 */
function fakePdfConverter(): void
{
    app()->instance(PdfConverter::class, new class implements PdfConverter
    {
        public function convert(string $docxPath, string $pdfPath): string
        {
            file_put_contents($pdfPath, "%PDF-1.4\n% test\n");

            return $pdfPath;
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
}

require_once __DIR__.'/Support/offers.php';
