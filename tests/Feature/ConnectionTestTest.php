<?php

use App\Ai\Config\AiAgentProfile;
use App\Ai\Config\ConnectionTester;
use App\Ai\Jobs\RunConnectionTestJob;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiRunStatus;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Filament\Clusters\Settings\Pages\AiAgentSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const TEST_KEY = 'test-secret-key-1234567890abcd';

/**
 * Draait de verbindingstest zoals de knop dat doet (queue = sync in tests).
 */
function runConnectionTest(AiAgentProfile $profile, array $overrides = []): AiRun
{
    $state = AiAgentSettings::toFormState($profile);
    $attributes = [...AiAgentSettings::attributesFromState($state), 'api_key' => $profile->api_key, ...$overrides];

    $run = RunTracker::queue(AiTask::ConnectionTest, ConnectionTester::class, $profile, null, ConnectionTester::STEPS, 'Verbindingstest', $profile);
    RunConnectionTestJob::dispatch($run->id(), $attributes, $profile->id);

    return $run->run->refresh();
}

/**
 * @return array<string, mixed>
 */
function stepResult(AiRun $run, string $key): array
{
    return collect($run->result['steps'])->firstWhere('key', $key);
}

beforeEach(function () {
    $this->logFile = storage_path('logs/test-'.uniqid().'.log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);
});

afterEach(function () {
    @unlink($this->logFile);
});

it('reports an invalid api key in plain language without leaking the key', function () {
    Http::fake(['ollama.test/*' => Http::response(['error' => 'Unauthorized: '.TEST_KEY], 401)]);
    $profile = aiProfile();

    $run = runConnectionTest($profile);
    Log::error('Provider-fout met sleutel '.TEST_KEY);

    expect($run->status)->toBe(AiRunStatus::Failed)
        ->and(stepResult($run, 'endpoint')['passed'])->toBeFalse()
        ->and(stepResult($run, 'endpoint')['message'])->toBe('De API-sleutel is ongeldig of ingetrokken.')
        ->and(stepResult($run, 'models')['passed'])->toBeFalse()
        ->and(json_encode($run->result))->not->toContain(TEST_KEY)
        ->and((string) $run->error_message)->not->toContain(TEST_KEY)
        ->and(DB::table('ai_runs')->get()->toJson())->not->toContain(TEST_KEY)
        ->and(DB::table('activity_log')->get()->toJson())->not->toContain(TEST_KEY)
        ->and((string) @file_get_contents($this->logFile))->not->toContain(TEST_KEY)
        ->and($profile->refresh()->last_test_result['passed'])->toBeFalse();
});

it('passes steps 1 to 3 with a valid key and records latency, model and cost', function () {
    Http::fake(['ollama.test/api/chat' => Http::sequence()
        ->push(ollamaReply('OK'))
        ->push(ollamaReply('OK'))
        ->push(ollamaReply('OK'))
        ->push(ollamaReply('OK'))
        ->push(ollamaReply('OK'))
        ->push(ollamaReply('OK'))
        ->push(ollamaReply('OK'))
        ->push(ollamaReply('```json'."\n".'{"status": "ok"}'."\n".'```')),
    ]);
    $profile = aiProfile();

    $run = runConnectionTest($profile);

    expect($run->status)->toBe(AiRunStatus::Succeeded)
        ->and(stepResult($run, 'endpoint')['passed'])->toBeTrue()
        ->and(stepResult($run, 'endpoint')['latency_ms'])->toBeInt()
        ->and(stepResult($run, 'models')['passed'])->toBeTrue()
        ->and(stepResult($run, 'models')['message'])->toContain('model-cv')
        ->and(stepResult($run, 'structured')['passed'])->toBeTrue()
        ->and(stepResult($run, 'structured')['model'])->toBe('model-extract')
        ->and(stepResult($run, 'web')['passed'])->toBeNull()
        ->and($run->result['cost_eur'])->toBeGreaterThan(0)
        ->and($run->progress)->toBe(100)
        ->and($profile->refresh()->last_test_at)->not->toBeNull()
        ->and($profile->last_test_result['passed'])->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer '.TEST_KEY)
        && ! str_contains($request->body(), 'Mark') && ! str_contains($request->body(), 'birth'));
});

it('names the model that does not exist', function () {
    Http::fake(function ($request) {
        $model = json_decode($request->body(), true)['model'];

        return $model === 'model-cv'
            ? Http::response(['error' => "model 'model-cv' not found"], 404)
            : Http::response(ollamaReply('{"status":"ok"}'));
    });

    $run = runConnectionTest(aiProfile());

    expect(stepResult($run, 'endpoint')['passed'])->toBeTrue()
        ->and(stepResult($run, 'models')['passed'])->toBeFalse()
        ->and(stepResult($run, 'models')['message'])->toContain("Model 'model-cv' bestaat niet of is niet beschikbaar voor deze sleutel.");
});

it('translates provider errors into plain language', function (int $status, string $message) {
    Http::fake(['ollama.test/*' => Http::response(['error' => 'x'], $status)]);

    $run = runConnectionTest(aiProfile());

    expect(stepResult($run, 'endpoint')['message'])->toBe($message);
})->with([
    'rate limit' => [429, 'Te veel verzoeken of tegoed op; probeer later opnieuw of controleer het account.'],
    'server error' => [503, 'De provider is tijdelijk niet bereikbaar.'],
    'forbidden' => [403, 'De API-sleutel is ongeldig of ingetrokken.'],
]);

it('reports an unreachable endpoint', function () {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    $run = runConnectionTest(aiProfile());

    expect(stepResult($run, 'endpoint')['message'])->toBe('Het endpoint is niet bereikbaar. Controleer de basis-URL en de netwerkverbinding.');
});

it('tests unsaved form values without touching the saved profile', function () {
    Http::fake(['other.test/*' => Http::response(['error' => 'nope'], 401), 'ollama.test/*' => Http::response(ollamaReply('{"status":"ok"}'))]);
    $profile = aiProfile();

    $state = AiAgentSettings::toFormState($profile);
    $attributes = [...AiAgentSettings::attributesFromState($state), 'base_url' => 'https://other.test', 'api_key' => 'unsaved-new-key-123456789'];
    $run = RunTracker::queue(AiTask::ConnectionTest, ConnectionTester::class, $profile, null, ConnectionTester::STEPS, 'Test', $profile);
    RunConnectionTestJob::dispatch($run->id(), $attributes, null);

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://other.test'));
    expect($profile->refresh()->last_test_at)->toBeNull()
        ->and($run->run->refresh()->status)->toBe(AiRunStatus::Failed);
});
