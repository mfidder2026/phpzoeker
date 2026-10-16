<?php

use App\Domain\Candidates\Models\Candidate;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Candidates\RelationManagers\ProfilesRelationManager;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('shows the approve button for a draft master profile and approves it', function () {
    $candidate = Candidate::factory()->create();
    $profile = $candidate->profiles()->create(['version' => 1, 'data' => profileFixture()]);

    $this->actingAs(beheerder());

    $this->get("/kandidaten/{$candidate->id}/edit")->assertOk()->assertSeeLivewire(ProfilesRelationManager::class);

    Livewire::test(ProfilesRelationManager::class, ['ownerRecord' => $candidate, 'pageClass' => EditCandidate::class])
        ->assertActionVisible(TestAction::make('approve')->table($profile))
        ->callAction(TestAction::make('approve')->table($profile))
        ->assertNotified('Versie 1 is goedgekeurd en actief');

    expect($candidate->refresh()->active_profile_id)->toBe($profile->id);
});

it('approves the newest draft from the button at the top of the candidate page', function () {
    $candidate = Candidate::factory()->create();
    $candidate->profiles()->create(['version' => 1, 'data' => profileFixture()]);

    $this->actingAs(beheerder());

    Livewire::test(EditCandidate::class, ['record' => $candidate->getRouteKey()])
        ->assertActionVisible('approveProfile')
        ->callAction('approveProfile')
        ->assertNotified('Versie 1 is goedgekeurd en actief')
        ->assertActionHidden('approveProfile');

    expect($candidate->refresh()->activeProfile?->version)->toBe(1);
});

it('hides the top approve button when there is no draft', function () {
    $candidate = Candidate::factory()->create();

    $this->actingAs(beheerder());

    Livewire::test(EditCandidate::class, ['record' => $candidate->getRouteKey()])
        ->assertActionHidden('approveProfile');
});
