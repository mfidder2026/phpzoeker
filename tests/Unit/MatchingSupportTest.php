<?php

use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Support\ProfileFacts;
use App\Domain\Matching\Support\RequirementYears;
use App\Domain\Matching\Support\Technologies;
use Carbon\CarbonImmutable;

function technologies(): Technologies
{
    return new Technologies(['postgres' => 'postgresql', 'reactjs' => 'react', 'k8s' => 'kubernetes']);
}

/**
 * @param  array<string, mixed>  $profile
 */
function facts(array $profile): ProfileFacts
{
    return new ProfileFacts(new Candidate(['name' => 'Test Persoon']), $profile, technologies(), CarbonImmutable::parse('2026-10-15'));
}

it('normalizes technology names with aliases and versions', function (string $name, string $expected) {
    expect(technologies()->normalize($name))->toBe($expected);
})->with([
    ['Postgres', 'postgresql'],
    ['PostgreSQL', 'postgresql'],
    ['PHP 8.3', 'php'],
    ['Laravel 11', 'laravel'],
    ['ReactJS', 'react'],
]);

it('measures coverage of the requested technologies', function () {
    $tech = technologies();

    expect($tech->coverage($tech->set(['PHP', 'Postgres', 'Go']), $tech->set(['php', 'PostgreSQL', 'Laravel', 'Docker'])))->toEqualWithDelta(2 / 3, 0.001)
        ->and($tech->coverage([], ['php']))->toBeNull()
        ->and($tech->mentionedIn('Postgres', 'Ervaring met PostgreSQL en Redis'))->toBeTrue()
        ->and($tech->mentionedIn('Go', 'Goede communicatie'))->toBeFalse();
});

it('computes years per technology from the work periods only', function () {
    $facts = facts([
        'werkervaring' => [
            ['van' => '2025-08', 'tot' => 'heden', 'functie' => 'Developer', 'bullets' => [], 'technologieen' => ['Laravel']],
            ['van' => '2019', 'tot' => '2021', 'functie' => 'Developer', 'bullets' => ['Bouwde API\'s met Laravel'], 'technologieen' => ['PHP']],
            ['van' => '2020-01', 'tot' => '2020-12', 'functie' => 'Developer', 'bullets' => [], 'technologieen' => ['PHP', 'Laravel']],
        ],
        'kernexpertise' => [['kop' => 'Backend', 'items' => [['naam' => 'Docker', 'sinds' => '2017']]]],
        'eerdere_ervaring' => [['periode' => '2010–2018', 'functie' => 'Stagiair Laravel', 'organisatie' => 'X']],
    ]);

    // 2019–2021 (overlapt met 2020) = 3 jaar, plus 08-2025 t/m 15-10-2026 ≈ 1,2 jaar.
    expect($facts->yearsWith('Laravel')['years'])->toEqualWithDelta(4.2, 0.1)
        ->and($facts->yearsWith('Laravel')['since'])->toBe('2019-01')
        ->and($facts->yearsWith('Docker')['years'])->toEqualWithDelta(9.8, 0.1)
        ->and($facts->yearsWith('COBOL')['years'])->toBe(0.0)
        ->and($facts->professionalYears())->toEqualWithDelta(4.2, 0.1);
});

it('removes the name and contact details from the profile for the prompt', function () {
    $prompt = json_encode(facts([
        'naam' => 'Test Persoon',
        'profieltekst' => 'Test Persoon is bereikbaar via test@example.test of 06-12345678.',
    ])->forPrompt());

    expect($prompt)->not->toContain('Test Persoon')->not->toContain('test@example.test')->not->toContain('06-12345678');
});

it('reads years and technology from a requirement text', function (array $requirement, ?array $expected) {
    expect(RequirementYears::parse($requirement, ['laravel', 'php', 'laravel nova'], technologies()))->toBe($expected);
})->with([
    [['tekst' => 'Minimaal 5 jaar ervaring met Laravel'], ['years' => 5.0, 'technology' => 'laravel']],
    [['tekst' => '3+ jaar Laravel Nova'], ['years' => 3.0, 'technology' => 'laravel nova']],
    [['tekst' => 'Ruime ervaring', 'min_jaren' => 4, 'technologie' => 'PHP'], ['years' => 4.0, 'technology' => 'PHP']],
    [['tekst' => 'Minimaal 2 jaar werkervaring als developer'], ['years' => 2.0, 'technology' => null]],
    [['tekst' => 'Kennis van Laravel'], null],
]);
