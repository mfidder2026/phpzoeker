<?php

use App\Domain\Candidates\Models\Candidate;
use App\Domain\Offers\Actions\CreateFollowUpMail;
use App\Domain\Offers\Actions\OfferWorkflow;
use App\Domain\Offers\Actions\RecordManualOffer;
use App\Domain\Offers\Actions\SendOutboundEmail;
use App\Domain\Offers\Enums\OfferActivityType;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Jobs\ComputeResponseStatsJob;
use App\Domain\Offers\Jobs\ResponseDueJob;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OfferSnapshot;
use App\Domain\Offers\Notifications\ResponseOverdueNotification;
use App\Domain\Offers\Support\ResponseTerm;
use App\Domain\Relations\Actions\MergeOrganizations;
use App\Domain\Relations\Enums\OrganizationType;
use App\Domain\Relations\Models\Organization;
use App\Enums\Role;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Offers\Pages\OfferWizard;
use App\Filament\Resources\Offers\Pages\ViewOffer;
use App\Http\Controllers\OfferFileController;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 10:32:00');
    Storage::fake('local');
    Notification::fake();
    fakePdfConverter();
    aiProfile();
    withoutConsent();
});

/**
 * Michel biedt Alex op maandag 16-11-2026 om 10:32 aan bij HeadFirst; de deadline lag ervoor.
 */
function sentOffer(User $user): Offer
{
    $offer = preparedOffer($user, offerAssignment(attributes: ['deadline_at' => '2026-11-13 12:00:00']));
    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    return $offer->refresh();
}

it('lists a sent offer in the register with the expected response', function () {
    $michel = beheerder();
    $offer = sentOffer($michel);

    expect($offer->expected_response_at?->setTimezone(ResponseTerm::TIMEZONE)->format('d-m-Y H:i'))->toBe('23-11-2026 17:00')
        ->and($offer->response_term_days)->toBe(5)
        ->and($offer->response_term_source)->toBe('global');

    $this->actingAs($michel);

    Livewire::test(ListOffers::class)
        ->assertCanSeeTableRecords([$offer])
        ->assertSee('16-11-2026 10:32')
        ->assertSee('Alex Voorbeeld')
        ->assertSee('HeadFirst')
        ->assertSee('95,00')
        ->assertSee('Verzonden');
});

it('uses the default response term of the organization', function () {
    $user = beheerder();
    $assignment = offerAssignment(attributes: ['deadline_at' => '2026-11-13 12:00:00']);
    Organization::query()->where('name', 'HeadFirst')->update(['default_response_term_days' => 3]);

    $offer = preparedOffer($user, $assignment);
    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    expect($offer->refresh()->expected_response_at?->setTimezone(ResponseTerm::TIMEZONE)->format('d-m-Y H:i'))->toBe('19-11-2026 17:00')
        ->and($offer->response_term_source)->toBe('organization');
});

it('shows the offer exactly as sent, even after the master profile changed', function () {
    $user = beheerder();
    $offer = sentOffer($user);
    $profile = $offer->candidate->activeProfile;
    $offer->candidate->profiles()->create(['version' => 2, 'data' => [...$profile->data, 'profieltekst' => 'Nieuwe tekst'], 'approved_at' => now()]);

    $this->actingAs($user)
        ->get('/aanbiedingsregister/'.$offer->id.'?tab=zo-zag-de-aanbieding-eruit::data::tab')
        ->assertOk()
        ->assertSee('Ongewijzigd sinds verzending')
        ->assertSee('Download .eml')
        ->assertSee('recruiter@x.example')
        ->assertSee('CV Alex Voorbeeld - Fremen.pdf');

    $snapshot = $offer->snapshot;
    $this->get(OfferFileController::url($offer, $snapshot->eml_path, 'aanbieding.eml'))
        ->assertOk()
        ->assertDownload('aanbieding.eml');
});

it('refuses every change of a snapshot, in the app and in the database', function () {
    $user = beheerder();
    $snapshot = sentOffer($user)->snapshot;

    expect(fn () => $snapshot->update(['email_text' => 'gewijzigd']))->toThrow(LogicException::class)
        // In een savepoint: op PostgreSQL breekt de geweigerde UPDATE anders de hele testtransactie af.
        ->and(fn () => DB::transaction(fn () => DB::table('offer_snapshots')->where('id', $snapshot->id)->update(['email_text' => 'gewijzigd'])))->toThrow(QueryException::class)
        ->and(fn () => $snapshot->delete())->toThrow(LogicException::class);

    expect(DB::table('activity_log')->where('description', 'Poging om een snapshot te wijzigen geweigerd')->exists())->toBeTrue()
        ->and($snapshot->refresh()->isIntact())->toBeTrue();
});

it('detects a changed attachment of a snapshot', function () {
    $user = beheerder();
    $snapshot = sentOffer($user)->snapshot;

    Storage::disk('local')->put($snapshot->attachments[0]['path'], 'vervalst');

    expect($snapshot->isIntact())->toBeFalse();
});

it('marks an overdue offer, notifies and follows up with a new expected response', function () {
    $michel = beheerder();
    app(GeneralSettings::class)->fill(['admin_email' => $michel->email])->save();
    $sender = userWithRoles(Role::Accountmanager);
    $offer = sentOffer($sender);

    Carbon::setTestNow('2026-11-24 09:00:00');

    expect(ResponseTerm::indicator($offer->refresh()))->toBe(['state' => 'late', 'label' => '1 werkdag te laat'])
        ->and(Offer::query()->overdue()->count())->toBe(1);

    (new ResponseDueJob)->handle(app(GeneralSettings::class));
    (new ResponseDueJob)->handle(app(GeneralSettings::class));

    Notification::assertSentToTimes($sender, ResponseOverdueNotification::class, 1);
    Notification::assertSentToTimes($michel, ResponseOverdueNotification::class, 1);

    $followUp = app(CreateFollowUpMail::class)->handle($offer);
    expect($followUp->to)->toBe(['recruiter@x.example'])
        ->and($followUp->cc)->toBe(['mark@fremen.app', 'michel@fremen.app'])
        ->and($followUp->subject)->toStartWith('Re: ')
        ->and($followUp->in_reply_to)->toBe($offer->offerEmail()->internet_message_id);

    app(OfferWorkflow::class)->approveMail($followUp, $michel);
    app(SendOutboundEmail::class)->handle($followUp, $michel);

    $offer->refresh();
    expect($offer->follow_up_count)->toBe(1)
        ->and($offer->expected_response_at?->setTimezone(ResponseTerm::TIMEZONE)->format('d-m-Y H:i'))->toBe('27-11-2026 17:00')
        ->and($offer->activities()->where('type', OfferActivityType::FollowUp->value)->exists())->toBeTrue()
        ->and(Offer::query()->overdue()->count())->toBe(0);
});

it('registers an offer made outside the app from an .eml file', function () {
    $michel = beheerder();
    $candidate = offerCandidate(['name' => 'Sam Proefpersoon']);
    $eml = "From: Michel <michel@fremen.app>\r\nTo: klant@direct.example\r\nCc: mark@fremen.app, michel@fremen.app\r\nSubject: Aanbieding Sam\r\nDate: Mon, 16 Nov 2026 09:15:00 +0100\r\nMessage-ID: <abc123@fremen.app>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBeste klant,\r\nHierbij Sam.\r\n--b1\r\nContent-Type: application/pdf; name=\"CV Sam.pdf\"\r\nContent-Disposition: attachment; filename=\"CV Sam.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQK\r\n--b1--\r\n";
    Storage::disk('local')->put('offers/uploads/mail.eml', $eml);

    $offer = app(RecordManualOffer::class)->handle([
        'candidate_id' => $candidate->id,
        'new_title' => 'PHP Developer bij directe klant',
        'new_client' => 'Directe Klant BV',
        'organization_name' => 'Directe Klant BV',
        'channel' => OfferChannel::Manual->value,
        'sent_at' => '2026-11-16 09:15',
        'offered_rate' => 75,
        'sent_by' => $michel->id,
        'status' => OfferStatus::Verzonden->value,
        'note' => 'Telefonisch aangeboden en daarna gemaild',
        'eml_path' => 'offers/uploads/mail.eml',
    ], $michel);

    $email = $offer->offerEmail();

    expect($offer->is_manual)->toBeTrue()
        ->and($offer->channel)->toBe(OfferChannel::Manual)
        ->and($offer->status)->toBe(OfferStatus::Verzonden)
        ->and($email?->from)->toBe('michel@fremen.app')
        ->and($email?->to)->toBe(['klant@direct.example'])
        ->and($email?->cc)->toBe(['mark@fremen.app', 'michel@fremen.app'])
        ->and($email?->subject)->toBe('Aanbieding Sam')
        ->and($offer->snapshot?->kind)->toBe(OfferSnapshot::KIND_MANUAL)
        ->and(collect($offer->snapshot?->attachments)->pluck('name')->all())->toBe(['CV Sam.pdf'])
        ->and($offer->contact?->email)->toBe('klant@direct.example');

    $this->actingAs($michel);
    Livewire::test(ListOffers::class)->assertCanSeeTableRecords([$offer])->assertSee('Handmatig');
});

it('renders the wizard, the register detail tabs, relations and manual registration', function () {
    $michel = beheerder();
    $offer = preparedOffer($michel);

    $this->actingAs($michel);
    $this->get('/aanbiedingsregister/'.$offer->id.'/aanbieden')->assertOk()->assertSee('Grounding-rapport')->assertSee('recruiter@x.example');
    Livewire::test(OfferWizard::class, ['record' => $offer->id])->assertActionVisible('send');

    foreach (['overzicht', 'tijdlijn', 'opdracht-toen', 'notities'] as $tab) {
        $this->get('/aanbiedingsregister/'.$offer->id.'?tab='.$tab.'::data::tab')->assertOk();
    }

    $this->get('/relaties')->assertOk()->assertSee('HeadFirst');
    $this->get('/relaties/'.Organization::query()->where('name', 'HeadFirst')->value('id').'/edit')->assertOk();
    $this->get('/handmatig-registreren')->assertOk()->assertSee('.eml-bestand');
    $this->get('/aanbiedingsregister')->assertOk()->assertSee('Reactie verlopen');
});

it('adds notes and changes status along the state machine only', function () {
    $michel = beheerder();
    $offer = sentOffer($michel);
    $this->actingAs($michel);

    Livewire::test(ViewOffer::class, ['record' => $offer->id])
        ->callAction('addNote', data: ['text' => 'Recruiter gebeld: klant kijkt deze week.'])
        ->callAction('changeStatus', data: ['status' => OfferStatus::Afgewezen->value, 'rejection' => 'tarief', 'reason' => 'boven budget']);

    expect($offer->refresh()->status)->toBe(OfferStatus::Afgewezen)
        ->and($offer->status_reason)->toBe('Tarief te hoog: boven budget')
        ->and($offer->activities()->where('type', 'note')->first()?->payload['text'])->toContain('Recruiter gebeld');

    expect(fn () => app(OfferWorkflow::class)->changeStatus($offer, OfferStatus::Verzonden, null, $michel))->toThrow(InvalidArgumentException::class);
});

it('shows a kandidaat only the own offers', function () {
    $michel = beheerder();
    $offer = sentOffer($michel);
    $other = userWithRoles(Role::Kandidaat);
    $other->update(['candidate_id' => Candidate::factory()->create()->id]);

    $this->actingAs(kandidaatUser($offer->candidate));
    Livewire::test(ListOffers::class)->assertCanSeeTableRecords([$offer]);

    $this->actingAs($other->refresh());
    Livewire::test(ListOffers::class)->assertCanNotSeeTableRecords([$offer]);
    $this->get('/aanbiedingsregister/'.$offer->id)->assertNotFound();
});

it('exports the register as csv', function () {
    $michel = beheerder();
    sentOffer($michel);
    $this->actingAs($michel);

    Livewire::test(ListOffers::class)->callAction('export')->assertFileDownloaded('aanbiedingsregister-2026-11-16.csv');
});

it('measures the response time per organization and merges duplicates', function () {
    $michel = beheerder();
    $headfirst = Organization::query()->create(['name' => 'HeadFirst Group', 'type' => OrganizationType::Intermediair, 'default_response_term_days' => 1]);

    foreach ([4, 5, 6] as $index => $days) {
        Offer::query()->forceCreate([
            'assignment_id' => openAssignment()->id, 'candidate_id' => offerCandidate()->id, 'dedupe_group_id' => (string) Str::uuid(),
            'organization_id' => $headfirst->id, 'channel' => 'email', 'status' => 'reactie_ontvangen', 'consent_status' => 'niet_nodig',
            'sent_at' => now()->subDays(20 + $index), 'response_time_workdays' => $days, 'response_received_at' => now(),
        ]);
    }

    (new ComputeResponseStatsJob)->handle();

    expect($headfirst->refresh()->measured_avg_response_days)->toBe('5.0')
        ->and($headfirst->measured_sample_size)->toBe(3);

    Notification::assertSentTo($michel, DatabaseNotification::class);

    $duplicate = Organization::query()->create(['name' => 'Headfirst', 'type' => OrganizationType::Intermediair, 'email_domains' => ['headfirst.nl']]);
    $duplicate->contacts()->create(['name' => 'Rianne', 'email' => 'rianne@headfirst.nl']);
    app(MergeOrganizations::class)->handle($duplicate, $headfirst);

    expect($duplicate->refresh()->merged_into_id)->toBe($headfirst->id)
        ->and($headfirst->contacts()->count())->toBe(1)
        ->and($headfirst->refresh()->email_domains)->toBe(['headfirst.nl']);
});
