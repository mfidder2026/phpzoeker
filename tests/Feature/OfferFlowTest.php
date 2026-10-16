<?php

use App\Domain\Assignments\Models\AssignmentSource;
use App\Domain\Matching\Actions\MatchCandidate;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Enums\Recommendation;
use App\Domain\Offers\Actions\CreateOffer;
use App\Domain\Offers\Actions\OfferWorkflow;
use App\Domain\Offers\Actions\RequestConsent;
use App\Domain\Offers\Actions\SendOutboundEmail;
use App\Domain\Offers\Enums\ConsentStatus;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Exceptions\DuplicateOfferException;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OfferSnapshot;
use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Enums\Role;
use App\Filament\Resources\Assignments\Pages\ViewAssignment;
use App\Filament\Resources\Matches\MatchActions;
use App\Filament\Resources\Matches\Pages\ViewMatch;
use App\Filament\Resources\Offers\OfferResource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 10:32:00');
    Storage::fake('local');
    Notification::fake();
    fakePdfConverter();
    aiProfile();
});

it('offers a candidate by mail with the right recipients, attachment and practical details', function () {
    withoutConsent();
    $user = beheerder();
    $offer = preparedOffer($user);

    expect($offer->status)->toBe(OfferStatus::KlaarVoorVerzending);

    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    $mails = sentMails();
    expect($mails)->toHaveCount(1);

    $mail = $mails[0];
    expect(array_map(fn ($address) => $address->getAddress(), $mail->getTo()))->toBe(['recruiter@x.example'])
        ->and(array_map(fn ($address) => $address->getAddress(), $mail->getCc()))->toBe(['mark@fremen.app', 'michel@fremen.app'])
        ->and(array_map(fn ($part) => $part->getFilename(), $mail->getAttachments()))->toBe(['CV Alex Voorbeeld - Fremen.pdf'])
        ->and($mail->getTextBody())->toContain('€ 95 per uur, exclusief btw')->toContain('40 uur per week')
        ->and($mail->getTextBody())->toContain('Beste Rianne Recruiter,')
        ->and($mail->getSubject())->toBe('Aanbieding Alex Voorbeeld – Senior PHP / Laravel Developer – R123');

    $offer->refresh();
    $email = $offer->offerEmail();

    expect($offer->status)->toBe(OfferStatus::Verzonden)
        ->and($offer->sent_at?->toDateTimeString())->toBe('2026-11-16 10:32:00')
        ->and($offer->sent_by)->toBe($user->id)
        ->and($email?->status)->toBe(OutboundEmailStatus::Verzonden)
        ->and($email?->internet_message_id)->toEndWith('@fremen.app')
        ->and($email?->provider_message_id)->not->toBeNull()
        ->and($offer->match?->status)->toBe(MatchStatus::Aangeboden)
        ->and($offer->snapshot)->not->toBeNull()
        ->and($offer->snapshot?->isIntact())->toBeTrue();
});

it('never sends the same mail twice', function () {
    withoutConsent();
    $user = beheerder();
    $offer = preparedOffer($user);

    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);
    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    expect(sentMails())->toHaveCount(1);
});

it('refuses to send a mail that a human has not approved (R1)', function () {
    $user = beheerder();
    $offer = preparedOffer($user);
    $email = $offer->offerEmail();
    $email->forceFill(['status' => OutboundEmailStatus::Concept, 'approved_by' => null])->save();

    expect(fn () => app(SendOutboundEmail::class)->handle($email, $user))->toThrow(InvalidArgumentException::class);
    expect(sentMails())->toBe([]);
});

it('blocks a second offer of the same candidate in the same dedupe group', function () {
    withoutConsent();
    $user = beheerder();
    $first = offerAssignment('HeadFirst');
    $offer = preparedOffer($user, $first);
    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    $sameJobElsewhere = offerAssignment('Yacht', 'jobs@yacht.example', groupId: $first->dedupe_group_id);

    expect(fn () => app(CreateOffer::class)->handle($sameJobElsewhere, $offer->candidate, $user))
        ->toThrow(DuplicateOfferException::class, 'Alex Voorbeeld is al aangeboden via HeadFirst op 16-11-2026');
});

it('allows a new offer after the earlier one was withdrawn', function () {
    $user = beheerder();
    $assignment = offerAssignment();
    $candidate = offerCandidate();
    $offer = app(CreateOffer::class)->handle($assignment, $candidate, $user);

    app(OfferWorkflow::class)->withdraw($offer, 'Verkeerde route', $user);

    expect(app(CreateOffer::class)->handle($assignment, $candidate, $user)->id)->not->toBe($offer->id);
});

it('stops an invented skill in the grounding check and disables approval', function () {
    $user = beheerder();
    $tailored = tailoredCvJson(overrides: ['profieltekst' => 'Kubernetes-expert met 5 jaar Kubernetes-ervaring en ruime ervaring met Laravel.']);
    $offer = preparedOffer($user, offerAssignment(attributes: ['title' => 'Kubernetes-expert', 'requirements' => [['id' => 'E1', 'tekst' => 'Kubernetes-expert met 5+ jaar ervaring', 'knock_out' => true, 'categorie' => 'ervaring']]]), tailored: $tailored);

    $cv = $offer->latestCv;
    $years = collect($cv->grounding_report['checks'])->firstWhere('check', 'Aantallen jaren kloppen met het master-profiel');

    expect($cv->grounding_passed)->toBeFalse()
        ->and($years['passed'])->toBeFalse()
        ->and($years['detail'])->toContain('5 jaar Kubernetes');

    expect(fn () => app(OfferWorkflow::class)->approveCv($cv, $user))->toThrow(InvalidArgumentException::class, 'grounding-check');
});

it('restores fixed work experience fields and drops invented certificates', function () {
    $user = beheerder();
    $profile = json_decode(tailoredCvJson(), true);
    $profile['werkervaring'][0]['functie'] = 'Lead Architect';
    $profile['certificaten'][] = ['jaar' => '2024', 'naam' => 'CKA Kubernetes'];

    $offer = preparedOffer($user, tailored: (string) json_encode($profile));
    $cv = $offer->latestCv;

    expect($cv->content['werkervaring'][0]['functie'])->toBe('Senior Developer')
        ->and(collect($cv->content['certificaten'])->pluck('naam')->all())->not->toContain('CKA Kubernetes')
        ->and($cv->grounding_report['restored'])->not->toBeEmpty()
        ->and($cv->grounding_passed)->toBeTrue();
});

it('mentions a planned holiday during the assignment in the mail', function () {
    withoutConsent();
    $user = beheerder();
    $candidate = offerCandidate(['name' => 'Sam Proefpersoon', 'hourly_rate' => 75, 'min_hourly_rate' => 75]);
    $candidate->absences()->createQuietly(['type' => 'vakantie', 'starts_on' => '2026-12-18', 'ends_on' => '2026-12-31']);

    $offer = preparedOffer($user, offerAssignment(attributes: ['start_asap' => false, 'start_date' => '2026-12-14', 'duration_months' => 6]), $candidate->refresh());

    expect($offer->offerEmail()?->body_text)->toContain('met uitzondering van een geplande vakantie van 18 t/m 31 december 2026')
        ->and($offer->offerEmail()?->body_text)->toContain('€ 75 per uur, exclusief btw');
});

it('keeps the birth date out of prompts, CV and mail', function () {
    withoutConsent();
    $user = beheerder();
    $offer = preparedOffer($user);
    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    Http::assertSent(fn (Request $request): bool => ! str_contains(json_encode($request->data()), '1985') && ! str_contains(json_encode($request->data()), '14-03'));

    $docx = (string) Storage::disk('local')->get($offer->latestCv->docx_path);
    $zip = new ZipArchive;
    $tmp = tempnam(sys_get_temp_dir(), 'cv');
    file_put_contents($tmp, $docx);
    $zip->open($tmp);
    // Alleen de zichtbare tekst: in de opmaak staat toevallig een kolombreedte van 1985 twips.
    $documentXml = strip_tags((string) $zip->getFromName('word/document.xml'));
    $zip->close();

    expect($documentXml)->not->toContain('1985')->not->toContain('14-03')
        ->and($offer->offerEmail()?->body_text)->not->toContain('1985')
        ->and(sentMails()[0]->getTextBody())->not->toContain('1985');
});

it('submits via a platform with only an internal mail and sends after the external reference', function () {
    withoutConsent();
    $user = beheerder();
    $offer = preparedOffer($user, channel: OfferChannel::Platform);
    $email = $offer->offerEmail();

    expect($email->to)->toBe(['mark@fremen.app', 'michel@fremen.app'])
        ->and(collect($email->attachments)->pluck('name')->all())->toContain('Indieningspakket Alex Voorbeeld.zip');

    app(SendOutboundEmail::class)->handle($email, $user);

    expect($offer->refresh()->status)->toBe(OfferStatus::KlaarVoorVerzending)
        ->and(array_map(fn ($address) => $address->getAddress(), sentMails()[0]->getTo()))->toBe(['mark@fremen.app', 'michel@fremen.app']);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path("offers/{$offer->id}/package/indieningspakket.zip"));
    expect($zip->getFromName('antwoorden-per-eis.txt'))->toContain('Sinds 2015 werkzaam met Laravel.');
    $zip->close();

    app(OfferWorkflow::class)->markSubmitted($offer, 'STR-4711', $user);

    expect($offer->refresh()->status)->toBe(OfferStatus::Verzonden)
        ->and($offer->external_reference)->toBe('STR-4711')
        ->and($offer->snapshot?->kind)->toBe(OfferSnapshot::KIND_PLATFORM)
        ->and(sentMails())->toHaveCount(1);
});

it('asks the candidate for consent and continues after the signed link', function () {
    $user = beheerder();
    $offer = preparedOffer($user);

    expect($offer->status)->toBe(OfferStatus::WachtOpKandidaat);

    app(RequestConsent::class)->handle($offer, $user);
    $consentMail = sentMails()[0];

    expect(array_map(fn ($address) => $address->getAddress(), $consentMail->getTo()))->toBe(['alex@fremen.test'])
        ->and($consentMail->getTextBody())->toContain('/akkoord/'.$offer->id);

    $url = RequestConsent::url($offer, 72);
    $this->get($url)->assertOk()->assertSee('Akkoord, bied me aan');
    $this->post($url, ['decision' => 'akkoord'])->assertOk()->assertSee('Dank je');

    expect($offer->refresh()->consent_status)->toBe(ConsentStatus::Akkoord)
        ->and($offer->status)->toBe(OfferStatus::KlaarVoorVerzending);

    $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
});

it('only lets staff send; a reviewer may look but not send', function () {
    $reviewer = userWithRoles(Role::Reviewer);
    $offer = Offer::query()->make();

    expect($reviewer->can('send', $offer))->toBeFalse()
        ->and($reviewer->can('viewAny', Offer::class))->toBeTrue()
        ->and(beheerder()->can('send', $offer))->toBeTrue();
});

it('starts an offer from the match and refuses a duplicate with a link', function () {
    $user = beheerder();
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $match = app(MatchCandidate::class)->handle(offerAssignment(), offerCandidate());

    $this->actingAs($user);

    Livewire\Livewire::test(ViewMatch::class, ['record' => $match->id])
        ->callAction('offer')
        ->assertRedirect(OfferResource::getUrl('wizard', ['record' => Offer::query()->sole()]));

    Livewire\Livewire::test(ViewMatch::class, ['record' => $match->id])
        ->callAction('offer')
        ->assertNotified('Alex Voorbeeld heeft al een aanbieding in voorbereiding voor deze opdracht (via HeadFirst)');

    expect(Offer::query()->count())->toBe(1)
        ->and($match->refresh()->status)->toBe(MatchStatus::Shortlist);
});

it('only asks "weet je het zeker" when a knock-out fails or the advice is not to offer', function () {
    $user = beheerder();
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $match = app(MatchCandidate::class)->handle(offerAssignment(), offerCandidate());

    expect(MatchActions::offerWarning($match))->toBeNull();

    $this->actingAs($user);
    Livewire\Livewire::test(ViewMatch::class, ['record' => $match->id])
        ->mountAction('offer')
        ->assertRedirect(OfferResource::getUrl('wizard', ['record' => Offer::query()->sole()]));

    $match->forceFill(['recommendation' => Recommendation::NietAanbieden])->save();
    expect(MatchActions::offerWarning($match))->toContain('niet aanbieden');
});

it('refuses to approve or send an offer mail without a recipient', function () {
    withoutConsent();
    $user = beheerder();
    $offer = preparedOffer($user);
    $email = $offer->offerEmail();

    $email->forceFill(['to' => [], 'status' => OutboundEmailStatus::Goedgekeurd])->save();
    expect(fn () => app(SendOutboundEmail::class)->handle($email, $user))->toThrow(InvalidArgumentException::class, 'geen ontvanger');

    $email->forceFill(['status' => OutboundEmailStatus::Concept, 'approved_by' => null])->save();
    expect(fn () => app(OfferWorkflow::class)->approveMail($email->refresh(), $user))->toThrow(InvalidArgumentException::class, 'Vul eerst een ontvanger in');
    expect(sentMails())->toBe([]);
});

it('shows for whom an assignment from a job alert is and where to open it', function () {
    $this->actingAs(beheerder());
    $assignment = openAssignment(['title' => 'Front-end React Developer']);
    $mailbox = Source::query()->create(['key' => 'alerts-mailbox', 'name' => 'Aanvragen-mailbox', 'type' => SourceType::MailboxAlert, 'enabled' => false]);
    Source::query()->create(['key' => 'freelance-nl', 'name' => 'Freelance.nl', 'type' => SourceType::Alert, 'enabled' => true, 'config' => ['sender_domains' => ['freelance.nl']]]);
    $posting = RawPosting::query()->create([
        'source_id' => $mailbox->id, 'external_id' => 'alert-1', 'url' => 'https://www.freelance.nl/opdracht/123', 'title' => 'Front-end React Developer',
        'body_text' => 'Samenvatting', 'content_hash' => 'x', 'received_at' => now(), 'status' => RawPostingStatus::Processed,
        'meta' => ['kind' => 'alert_item', 'sender_domain' => 'freelance.nl'],
    ]);
    AssignmentSource::query()->create(['assignment_id' => $assignment->id, 'source_id' => $mailbox->id, 'raw_posting_id' => $posting->id, 'url' => $posting->url, 'first_seen_at' => now(), 'last_seen_at' => now()]);

    Livewire\Livewire::test(ViewAssignment::class, ['record' => $assignment->id])
        ->assertSee('Voor wie en waar te reageren')
        ->assertSee('Opdracht openen bij Freelance.nl')
        ->assertSee('de opdrachtgever en de contactpersoon staan er niet in');
});
