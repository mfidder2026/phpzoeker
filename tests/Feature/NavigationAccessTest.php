<?php

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Geo\Geocoder;
use App\Enums\Role;
use App\Models\User;

$adminPages = [
    '/', '/opdrachten', '/matches', '/shortlist', '/aanbiedingsregister', '/handmatig-registreren',
    '/kandidaten', '/relaties', '/rapportages', '/ai-activiteit', '/bronnen', '/gebruikers', '/audit-log',
    '/instellingen/algemeen', '/instellingen/mail', '/instellingen/matching', '/instellingen/register',
    '/instellingen/meldingen', '/instellingen/ai-agent', '/instellingen/cv', '/instellingen/privacy', '/instellingen/ingest', '/bronnen/create',
];

it('shows every page of the left menu to the beheerder', function (string $url) {
    aiProfile();
    Candidate::factory()->create();

    $this->actingAs(beheerder())->get($url)->assertOk();
})->with($adminPages);

it('shows all menu items and candidate sub items in the sidebar', function () {
    $candidate = Candidate::factory()->create(['name' => 'Alex Voorbeeld']);

    $response = $this->actingAs(beheerder())->get('/')->assertOk();
    preg_match_all('/<span[^>]*class="[^"]*fi-sidebar-(?:item|group)-label[^"]*"[^>]*>\s*(.*?)\s*<\/span>/s', $response->getContent(), $labels);

    expect(array_map(fn (string $label): string => html_entity_decode(trim($label)), $labels[1]))->toBe([
        'Dashboard', 'Werk', 'Opdrachten', 'Matches', 'Shortlist', 'Aanbiedingen', 'Aanbiedingsregister', 'Handmatig registreren',
        'Mensen & relaties', 'Kandidaten', 'Relaties', 'Inzicht', 'Rapportages', 'AI-activiteit',
        'Beheer', 'Bronnen', 'Instellingen', 'Gebruikers', 'Audit-log',
    ]);

    $response->assertSee('/kandidaten/'.$candidate->id.'/edit', escape: false)
        ->assertSee('AI niet ingesteld');
});

it('limits a kandidaat to dashboard, matches, register and the own candidate page', function () {
    $own = Candidate::factory()->create(['name' => 'Eigen Kandidaat']);
    $other = Candidate::factory()->create(['name' => 'Andere Kandidaat']);
    $user = kandidaatUser($own);

    $this->actingAs($user);

    foreach (['/', '/matches', '/aanbiedingsregister', "/kandidaten/{$own->id}/edit"] as $url) {
        $this->get($url)->assertOk();
    }

    foreach (['/opdrachten', '/shortlist', '/handmatig-registreren', '/relaties', '/rapportages', '/bronnen',
        '/gebruikers', '/audit-log', '/instellingen/ai-agent', '/instellingen/mail', '/ai-activiteit'] as $url) {
        $this->get($url)->assertForbidden();
    }

    $this->get("/kandidaten/{$other->id}/edit")->assertNotFound();

    $this->get('/')->assertSee('Eigen Kandidaat')->assertDontSee('Andere Kandidaat')->assertDontSee('Audit-log');
});

it('does not show the api key on the AI agent page', function () {
    aiProfile(['api_key' => 'sk-page-secret-abcd']);

    $this->actingAs(beheerder())->get('/instellingen/ai-agent')
        ->assertOk()
        ->assertDontSee('sk-page-secret')
        ->assertSee('abcd');
});

it('denies the AI agent settings to an accountmanager', function () {
    $this->actingAs(userWithRoles(Role::Accountmanager))->get('/instellingen/ai-agent')->assertForbidden();
});

it('requires a role to enter the app', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertForbidden();
});

it('shows sources only to the beheerder and assignments to staff and reviewers', function () {
    $this->actingAs(userWithRoles(Role::Accountmanager))->get('/bronnen')->assertForbidden();
    $this->actingAs(userWithRoles(Role::Accountmanager))->get('/opdrachten')->assertOk();
    $this->actingAs(userWithRoles(Role::Reviewer))->get('/opdrachten')->assertOk();
});

it('renders the assignment detail with sources and travel distances', function () {
    app()->instance(Geocoder::class, new class implements Geocoder
    {
        public function geocode(string $place): ?array
        {
            return ['lat' => 51.92, 'lng' => 4.48, 'place' => $place, 'province' => 'Zuid-Holland'];
        }
    });
    Candidate::factory()->create(['name' => 'Alex Voorbeeld', 'city' => 'Rotterdam']);
    $assignment = Assignment::query()->create([
        'dedupe_group_id' => 'x', 'title' => 'Senior Laravel Developer', 'client_name' => 'Gemeente Voorbeeldstad',
        'city' => 'Vlaardingen', 'lat' => 51.9174, 'lng' => 4.3279, 'max_rate' => 95,
        'requirements' => [['id' => 'E1', 'tekst' => 'Minimaal 5 jaar Laravel', 'knock_out' => true, 'categorie' => 'ervaring']],
        'status' => 'nieuw', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $this->actingAs(beheerder())->get('/opdrachten/'.$assignment->id.'?tab=reisafstand::data::tab')
        ->assertOk()
        ->assertSee('Senior Laravel Developer')
        ->assertSee('Gemeente Voorbeeldstad')
        ->assertSee('Minimaal 5 jaar Laravel');
});
