<?php

use App\Ai\Support\PiiScrubber;
use App\Domain\Candidates\Models\Candidate;

it('removes birth date, phone number and e-mail before text goes to the model', function () {
    $candidate = Candidate::factory()->create(['birth_date' => '1985-03-14', 'phone' => '06-11122233']);

    $text = <<<'TXT'
        Alex Voorbeeld
        Geboortedatum: 14 maart 1985
        Rotterdam | 06-11122233 | alex@example.com
        Sinds 2014 developer bij Fremen.
        Datum: 14-03-1985 (staat los in het CV).
        Bel +31 6 1234 5678 of 010-1234567.
        Werkzaam van 2014 - 2021 aan 40 projecten.
        TXT;

    $scrubbed = app(PiiScrubber::class)->scrub($text, $candidate);

    expect($scrubbed)
        ->not->toContain('1985')
        ->not->toContain('11122233')
        ->not->toContain('alex@example.com')
        ->not->toContain('1234 5678')
        ->not->toContain('010-1234567')
        ->toContain('Sinds 2014 developer bij Fremen.')
        ->toContain('Werkzaam van 2014 - 2021 aan 40 projecten.');
});

it('keeps the city of a contact line that also holds the birth date', function () {
    $text = "Utrecht | 06-99887766 | joris@example.test | Geboren: 2 juni 1994\nAmersfoort | geb. datum | 02-06-1994";

    $scrubbed = app(PiiScrubber::class)->scrub($text);

    expect($scrubbed)
        ->toStartWith('Utrecht | ')
        ->toContain('[e-mail verwijderd] | [geboortedatum verwijderd]')
        ->toContain('Amersfoort | [geboortedatum verwijderd]')
        ->not->toContain('1994')
        ->not->toContain('juni');
});
