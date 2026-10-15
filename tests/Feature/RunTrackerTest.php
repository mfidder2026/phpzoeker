<?php

use App\Ai\AiGateway;
use App\Ai\Budget\AiBudgetGuard;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiRunStatus;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\Events\AiRunUpdated;
use App\Ai\Runs\RunCancelledException;
use App\Ai\Runs\RunTracker;
use App\Ai\Runs\TrackedRun;
use App\Livewire\AiGauge;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

$steps = [
    ['key' => 'analyse', 'label' => 'Analyseren', 'weight' => 15],
    ['key' => 'research', 'label' => 'Onderzoeken', 'weight' => 20, 'optional' => true],
    ['key' => 'write', 'label' => 'Schrijven', 'weight' => 40],
    ['key' => 'grounding', 'label' => 'Grounding', 'weight' => 15],
    ['key' => 'render', 'label' => 'Renderen', 'weight' => 10],
];

it('tracks weighted progress per step and broadcasts updates', function () use ($steps) {
    Event::fake([AiRunUpdated::class]);
    aiProfile();

    $run = RunTracker::start(AiTask::Cv, 'CvTailor', steps: $steps, subjectLabel: 'CV herschrijven — Test');

    expect($run->run->status)->toBe(AiRunStatus::Running)
        ->and($run->run->progress)->toBeNull();

    $run->step('analyse')->begin()->complete();
    expect($run->run->progress)->toBe(15);

    $run->step('research')->skip();
    $run->step('write')->begin();
    $run->step('write')->progress(0.5);
    // 15 + 20 (helft van 40) van 80 (onderzoek overgeslagen) = 43%.
    expect($run->run->progress)->toBe(43)
        ->and($run->run->current_step)->toBe('write');

    $run->finish(['ok' => true]);

    expect($run->run->refresh()->status)->toBe(AiRunStatus::Succeeded)
        ->and($run->run->progress)->toBe(100)
        ->and($run->run->finished_at)->not->toBeNull();

    Event::assertDispatched(AiRunUpdated::class, fn (AiRunUpdated $event): bool => $event->run['status'] === 'succeeded');
});

it('does not fake progress inside a step with unknown progress', function () {
    expect(TrackedRun::computeProgress([
        ['key' => 'a', 'weight' => 50, 'status' => 'done'],
        ['key' => 'b', 'weight' => 50, 'status' => 'running', 'progress' => null],
    ]))->toBe(50);
});

it('stops at the next step after cancellation', function () use ($steps) {
    aiProfile();
    $run = RunTracker::start(AiTask::Cv, 'CvTailor', steps: $steps);
    $run->step('analyse')->begin()->complete();

    AiRun::query()->whereKey($run->id())->update(['cancel_requested' => true]);

    expect(fn () => $run->step('write')->begin())->toThrow(RunCancelledException::class);
});

it('records tokens and estimated costs of every model call', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply('hallo', 1000, 500))]);
    aiProfile(['prices' => ['*' => ['input' => 2, 'output' => 10]]]);

    $run = RunTracker::start(AiTask::Mail, 'OfferMailWriter');
    app(AiGateway::class)->chat($run, AiTask::Mail, 'systeem', 'gebruiker');

    expect($run->run->refresh()->input_tokens)->toBe(1000)
        ->and($run->run->output_tokens)->toBe(500)
        ->and((float) $run->run->cost_eur)->toBe(0.007)
        ->and($run->run->model)->toBe('model-extract');

    Http::assertSent(fn ($request): bool => $request['model'] === 'model-mail');
});

it('retries once with the validation error when the output is invalid', function () {
    Http::fake(['ollama.test/*' => Http::sequence()->push(ollamaReply('geen json'))->push(ollamaReply('{"status":"ok"}'))]);
    aiProfile();

    $run = RunTracker::start(AiTask::Extract, 'Test');
    $data = app(AiGateway::class)->structured($run, AiTask::Extract, 's', 'u', ['type' => 'object']);

    expect($data)->toBe(['status' => 'ok']);
    Http::assertSentCount(2);
});

it('pauses automatic AI tasks when the daily budget is spent, but not user tasks', function () {
    $profile = aiProfile(['daily_budget_eur' => 1]);
    AiRun::query()->create(['agent' => 'X', 'task' => AiTask::Extract, 'status' => AiRunStatus::Succeeded, 'cost_eur' => 1.5]);

    $guard = app(AiBudgetGuard::class);

    expect($guard->isExhausted($profile))->toBeTrue()
        ->and(fn () => $guard->ensureAllowed(AiTask::Match, userInitiated: false, profile: $profile))->toThrow(AiProviderException::class);

    $guard->ensureAllowed(AiTask::Cv, userInitiated: true, profile: $profile);
});

it('shows the AI gauge states', function () use ($steps) {
    $admin = beheerder();
    $this->actingAs($admin);

    Livewire::test(AiGauge::class)->assertSee('AI niet ingesteld');

    $profile = aiProfile(['last_test_result' => ['passed' => true, 'steps' => []]]);
    Livewire::test(AiGauge::class)->assertSee('AI inactief');

    $run = RunTracker::start(AiTask::Cv, 'CvImporter', startedBy: $admin, steps: $steps, subjectLabel: 'CV importeren — Test');
    Livewire::test(AiGauge::class)->assertSee('AI bezig · 1 taak')->assertSee('CV importeren — Test');

    $run->fail('Iets ging mis');
    Livewire::test(AiGauge::class)->assertSee('AI-fout — bekijk');

    $profile->update(['last_test_result' => ['passed' => false, 'steps' => []]]);
    Livewire::test(AiGauge::class)->assertSee('AI-verbinding mislukt');
});
