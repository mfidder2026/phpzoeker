<?php

use App\Domain\Candidates\Events\CandidateValuesChanged;
use App\Domain\Candidates\Models\Candidate;
use Database\Seeders\CandidateSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('stores the birth date and phone number encrypted', function () {
    $candidate = Candidate::factory()->create(['birth_date' => '1985-03-14', 'phone' => '06-12345678']);

    $raw = DB::table('candidates')->where('id', $candidate->id)->first();

    expect($raw->birth_date)->not->toContain('1985')
        ->and(Crypt::decryptString($raw->birth_date))->toBe('1985-03-14')
        ->and($raw->phone)->not->toContain('12345678')
        ->and($candidate->refresh()->birth_date)->toBe('1985-03-14');
});

it('never exposes the birth date or phone number in array output', function () {
    $candidate = Candidate::factory()->create(['birth_date' => '1985-03-14', 'phone' => '06-12345678']);

    expect($candidate->toArray())->not->toHaveKeys(['birth_date', 'phone'])
        ->and($candidate->toJson())->not->toContain('1985');
});

it('uses the updated rate for new offers and requests a rematch', function () {
    Event::fake([CandidateValuesChanged::class]);

    $candidate = Candidate::factory()->create(['hourly_rate' => 95, 'min_hourly_rate' => 95]);
    $before = $candidate->offerSnapshot();

    $candidate->update(['hourly_rate' => 100]);

    expect($candidate->refresh()->offerSnapshot()['hourly_rate'])->toBe('100.00')
        ->and($before['hourly_rate'])->toBe('95.00');

    Event::assertDispatched(CandidateValuesChanged::class, fn (CandidateValuesChanged $event): bool => $event->candidate->is($candidate)
        && in_array('hourly_rate', $event->changedFields, true));
});

it('does not request a rematch for fields that do not affect matching', function () {
    Event::fake([CandidateValuesChanged::class]);

    $candidate = Candidate::factory()->create();
    $candidate->update(['job_title' => 'Lead Developer']);

    Event::assertNotDispatched(CandidateValuesChanged::class);
});

it('logs changes in the audit log without the birth date', function () {
    $candidate = Candidate::factory()->create();
    $candidate->update(['hourly_rate' => 101, 'birth_date' => '1990-02-02']);

    $activity = DB::table('activity_log')->where('log_name', 'kandidaten')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and((string) $activity->attribute_changes)->toContain('hourly_rate')
        ->and((string) $activity->attribute_changes)->not->toContain('birth_date')
        ->and((string) $activity->attribute_changes)->not->toContain('1990');
});

it('seeds candidates with absences and a search profile from yaml', function () {
    (new CandidateSeeder)->run(config_path('seed/candidates.example.yaml'));

    $candidate = Candidate::query()->where('slug', 'voorbeeld-kandidaat')->firstOrFail();

    expect($candidate->absences)->toHaveCount(1)
        ->and($candidate->absences->first()->starts_on->toDateString())->toBe('2026-12-18')
        ->and($candidate->absences->first()->ends_on->toDateString())->toBe('2026-12-31')
        ->and($candidate->searchProfile->min_hours)->toBe(24)
        ->and($candidate->searchProfile->accepted_contract_types)->toBe(['detachering', 'zzp', 'beide'])
        ->and($candidate->birth_date)->toBe('1990-01-01');
});
