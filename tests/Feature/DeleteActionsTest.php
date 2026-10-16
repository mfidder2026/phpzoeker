<?php

use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Actions\MatchCandidate;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Offers\Models\Offer;
use App\Domain\Relations\Enums\OrganizationType;
use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\AiAgentSettings;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Organizations\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Sources\Pages\EditSource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
});

function deletableSource(): Source
{
    return Source::query()->create([
        'key' => 'bron-'.uniqid(),
        'name' => 'Te verwijderen bron',
        'type' => SourceType::Feed,
        'base_url' => 'https://opdrachten.example.nl',
        'config' => ['feed_url' => 'https://opdrachten.example.nl/feed/'],
        'tos_reviewed_at' => now(),
        'tos_notes' => 'Feed toegestaan.',
        'enabled' => false,
    ]);
}

it('lets the beheerder delete and restore a candidate, keeping matches with an offer', function () {
    aiProfile();
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $candidate = matchableCandidate('senior-developer', ['name' => 'Alex Voorbeeld']);
    $withoutOffer = app(MatchCandidate::class)->handle(openAssignment(), $candidate);
    $withOffer = app(MatchCandidate::class)->handle(openAssignment(['title' => 'Senior PHP Developer']), $candidate);
    $offer = Offer::query()->forceCreate([
        'match_id' => $withOffer->id, 'assignment_id' => $withOffer->assignment_id, 'candidate_id' => $candidate->id,
        'dedupe_group_id' => (string) Str::uuid(), 'channel' => 'email', 'status' => 'verzonden', 'consent_status' => 'niet_nodig',
    ]);

    $this->actingAs(beheerder());

    Livewire::test(EditCandidate::class, ['record' => $candidate->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect($candidate->refresh()->trashed())->toBeTrue()
        ->and(CandidateMatch::query()->find($withoutOffer->id))->toBeNull()
        ->and(CandidateMatch::query()->find($withOffer->id))->not->toBeNull()
        ->and($offer->refresh()->candidate?->name)->toBe('Alex Voorbeeld');

    Livewire::test(ListCandidates::class)
        ->assertCanNotSeeTableRecords([$candidate])
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$candidate]);

    Livewire::test(EditCandidate::class, ['record' => $candidate->getRouteKey()])
        ->callAction(RestoreAction::class);

    expect($candidate->refresh()->trashed())->toBeFalse();
});

it('bulk deletes candidates from the list', function () {
    $candidates = Candidate::factory()->count(2)->create();

    $this->actingAs(beheerder());

    Livewire::test(ListCandidates::class)
        ->selectTableRecords($candidates)
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Candidate::query()->count())->toBe(0)
        ->and(Candidate::onlyTrashed()->count())->toBe(2);
});

it('hides candidate deletion from an accountmanager', function () {
    $candidate = Candidate::factory()->create();

    $this->actingAs(userWithRoles(Role::Accountmanager));

    Livewire::test(EditCandidate::class, ['record' => $candidate->getRouteKey()])
        ->assertActionHidden(DeleteAction::class);
});

it('deletes a source only while it has no postings', function () {
    $this->actingAs(beheerder());
    $unused = deletableSource();
    $used = deletableSource();
    RawPosting::query()->create([
        'source_id' => $used->id, 'external_id' => 'ext-1', 'title' => 'Developer', 'body_text' => 'Tekst',
        'content_hash' => RawPosting::hashContent('Developer', 'Tekst'), 'received_at' => now(), 'status' => RawPostingStatus::Pending,
    ]);

    Livewire::test(EditSource::class, ['record' => $used->getRouteKey()])
        ->assertActionHidden(DeleteAction::class);

    Livewire::test(EditSource::class, ['record' => $unused->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Source::query()->find($unused->id))->toBeNull()
        ->and(Source::query()->find($used->id))->not->toBeNull();
});

it('deletes a contact person of an organization', function () {
    $organization = Organization::query()->create(['name' => 'Voorbeeld BV', 'type' => OrganizationType::Intermediair]);
    $contact = $organization->contacts()->create(['name' => 'Sam Contact', 'email' => 'sam@voorbeeld.nl']);

    $this->actingAs(beheerder());

    Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $organization, 'pageClass' => EditOrganization::class])
        ->callAction(TestAction::make('delete')->table($contact));

    expect($organization->contacts()->count())->toBe(0);
});

it('deletes an inactive ai profile but never the active one', function () {
    $active = aiProfile();
    $fallback = aiProfile(['name' => 'Fallback', 'is_active' => false]);

    $this->actingAs(beheerder());

    $page = Livewire::test(AiAgentSettings::class)->assertActionHidden('deleteProfile');

    $page->set('profileId', $fallback->id)->callAction('deleteProfile');

    expect($fallback->newQuery()->find($fallback->id))->toBeNull()
        ->and($active->refresh()->is_active)->toBeTrue();
});
