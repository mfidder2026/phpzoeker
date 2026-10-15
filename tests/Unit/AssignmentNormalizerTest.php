<?php

use App\Domain\Assignments\Support\AssignmentNormalizer;
use App\Domain\Assignments\Support\TitleSimilarity;
use App\Domain\Geo\Distance;

it('parses hours from text', function (string $text, ?float $min, ?float $max) {
    expect(AssignmentNormalizer::parseHours($text))->toBe([$min, $max]);
})->with([
    ['32–40 uur per week', 32.0, 40.0],
    ['32 - 40 uur', 32.0, 40.0],
    ['Uren per week: 24 per week', 24.0, 24.0],
    ['36 uur per week', 36.0, 36.0],
    ['4 dagen per week', 32.0, 32.0],
    ['0,8 fte', 32.0, 32.0],
    ['geen uren genoemd', null, null],
]);

it('parses rates from text', function (string $text, ?float $rate) {
    expect(AssignmentNormalizer::parseRate($text))->toBe($rate);
})->with([
    ['max € 90,- p/u excl. btw', 90.0],
    ['tot 95 euro (incl. marge)', 95.0],
    ['€ 87,50 per uur', 87.5],
    ['Geen maximum', null],
]);

it('reads explicit zzp statements from the text', function (string $text, ?bool $expected) {
    expect(AssignmentNormalizer::zzpAllowedFromText($text))->toBe($expected);
})->with([
    ['ZZP is toegestaan', true],
    ['Zzp’ers zijn toegestaan', true],
    ['ZZP is niet toegestaan.', false],
    ['Geen zzp, alleen detachering', false],
    ['Detachering via een intermediair', null],
]);

it('reads the location label', function (string $text, ?string $city, ?string $province) {
    expect(AssignmentNormalizer::locationFromText($text))->toBe([$city, $province]);
})->with([
    ['Locatie: Vlaardingen - Zuid-Holland Uren per week: 24', 'Vlaardingen', 'Zuid-Holland'],
    ['Standplaats: Den Haag, hybride', 'Den Haag', null],
    ['Locatie: Bergen op Zoom - Noord-Brabant', 'Bergen op Zoom', 'Noord-Brabant'],
    ['Geen locatie', null, null],
]);

it('scores title similarity like pg_trgm', function () {
    expect(TitleSimilarity::score('Senior Laravel Developer', 'Senior Laravel Developer (m/v)'))->toBe(1.0)
        ->and(TitleSimilarity::score('Senior PHP Ontwikkelaar', 'Senior PHP Developer'))->toBe(1.0)
        ->and(TitleSimilarity::score('Senior Laravel Developer', 'Business Analist'))->toBeLessThan(0.2);
});

it('approximates road distance', function () {
    // Rotterdam ↔ Vlaardingen: ± 10 km hemelsbreed.
    $km = Distance::roadKm(51.9225, 4.4792, 51.9174, 4.3279);

    expect($km)->toBeGreaterThan(10)->toBeLessThan(18)
        ->and(Distance::travelMinutes(70.0))->toBe(60);
});
