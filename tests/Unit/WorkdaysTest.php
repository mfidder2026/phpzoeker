<?php

use App\Domain\Offers\Support\Workdays;
use Carbon\CarbonImmutable;

it('adds workdays and skips weekends and Dutch public holidays', function (string $from, int $days, string $expected) {
    expect(Workdays::add(CarbonImmutable::parse($from, 'Europe/Amsterdam'), $days)->format('d-m-Y H:i'))->toBe($expected);
})->with([
    'gewone week' => ['2026-11-16 10:32', 5, '23-11-2026 17:00'],
    'kerst en nieuwjaar' => ['2026-12-24 11:00', 5, '04-01-2027 17:00'],
    'koningsdag' => ['2027-04-26 09:00', 1, '28-04-2027 17:00'],
    'pasen' => ['2027-03-26 09:00', 1, '30-03-2027 17:00'],
    'hemelvaart' => ['2027-05-05 09:00', 1, '07-05-2027 17:00'],
    'pinksteren' => ['2027-05-14 09:00', 1, '18-05-2027 17:00'],
    'vanaf zaterdag' => ['2026-11-21 12:00', 1, '23-11-2026 17:00'],
]);

it('counts workdays between two moments', function () {
    expect(Workdays::between(CarbonImmutable::parse('2026-11-23 17:00'), CarbonImmutable::parse('2026-11-24 09:00')))->toBe(1.0)
        ->and(Workdays::between(CarbonImmutable::parse('2026-12-24 10:00'), CarbonImmutable::parse('2027-01-04 10:00')))->toBe(5.0)
        ->and(Workdays::between(CarbonImmutable::parse('2026-11-24'), CarbonImmutable::parse('2026-11-23')))->toBe(0.0);
});

it('knows which days are workdays', function () {
    expect(Workdays::isWorkday(CarbonImmutable::parse('2026-12-25')))->toBeFalse()
        ->and(Workdays::isWorkday(CarbonImmutable::parse('2026-12-28')))->toBeTrue()
        ->and(Workdays::isWorkday(CarbonImmutable::parse('2027-04-27')))->toBeFalse()
        ->and(Workdays::isWorkday(CarbonImmutable::parse('2026-11-21')))->toBeFalse();
});
