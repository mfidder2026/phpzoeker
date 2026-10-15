<?php

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Assignments\Models\AssignmentSource;
use App\Domain\Candidates\Enums\Seniority;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Actions\MatchCandidate;
use App\Domain\Offers\Actions\CreateOffer;
use App\Domain\Offers\Actions\GenerateTailoredCv;
use App\Domain\Offers\Actions\OfferWorkflow;
use App\Domain\Offers\Actions\PrepareOfferMail;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Models\Offer;
use App\Domain\Relations\Enums\OrganizationType;
use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\Source;
use App\Models\User;
use App\Settings\MailSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/*
 * Gedeelde helpers voor de tests van aanbiedingen (fase 4).
 */

function withoutConsent(): void
{
    $settings = app(MailSettings::class);
    $settings->require_candidate_consent = false;
    $settings->save();
}

/**
 * Opdracht "Senior Laravel Developer" via een intermediair met contactadres in de bron.
 */
function offerAssignment(string $intermediary = 'HeadFirst', string $email = 'recruiter@x.example', array $attributes = [], ?string $groupId = null): Assignment
{
    $assignment = $groupId === null
        ? openAssignment(['title' => 'Senior Laravel Developer', 'reference' => 'R123', 'max_rate' => 100, ...$attributes])
        : openAssignment(['title' => 'Senior Laravel Developer', 'reference' => 'R123', 'dedupe_group_id' => $groupId, ...$attributes]);

    $source = Source::query()->create([
        'key' => 'bron-'.Str::random(6), 'name' => $intermediary, 'type' => SourceType::Manual, 'enabled' => true,
        'tos_reviewed_at' => now(), 'tos_notes' => 'Test',
    ]);
    $organization = Organization::query()->create(['name' => $intermediary, 'type' => OrganizationType::Intermediair]);

    AssignmentSource::query()->create([
        'assignment_id' => $assignment->id, 'source_id' => $source->id, 'intermediary_name' => $intermediary,
        'organization_id' => $organization->id, 'contact_name' => 'Rianne Recruiter', 'contact_email' => $email,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    return $assignment->refresh();
}

function offerCandidate(array $attributes = []): Candidate
{
    return matchableCandidate('senior-developer', ['name' => 'Alex Voorbeeld', 'seniority' => Seniority::Senior, 'hourly_rate' => 95, 'min_hourly_rate' => 95, 'email' => 'alex@fremen.test', 'birth_date' => '1985-03-14', ...$attributes]);
}

/**
 * Doorloopt de wizard: aanbieding, CV, mail. Geeft de aanbieding terug, klaar om te versturen.
 */
function preparedOffer(User $user, ?Assignment $assignment = null, ?Candidate $candidate = null, OfferChannel $channel = OfferChannel::Email, ?string $tailored = null): Offer
{
    $assignment ??= offerAssignment();
    $candidate ??= offerCandidate();

    Http::fakeSequence('ollama.test/*')
        ->push(ollamaReply(assessmentJson()))
        ->push(ollamaReply($tailored ?? tailoredCvJson()))
        ->push(ollamaReply(groundingJson()))
        ->push(ollamaReply(mailWriterJson()));

    $match = app(MatchCandidate::class)->handle($assignment, $candidate);
    $offer = app(CreateOffer::class)->handle($assignment, $candidate, $user, $match, channel: $channel);

    $cv = app(GenerateTailoredCv::class)->handle($offer, GenerateTailoredCv::queueRun($offer, $user), $user);

    if (! $cv->grounding_passed) {
        return $offer->refresh();
    }

    app(OfferWorkflow::class)->approveCv($cv, $user);
    $email = app(PrepareOfferMail::class)->handle($offer->refresh(), PrepareOfferMail::queueRun($offer, $user), $user);
    app(OfferWorkflow::class)->approveMail($email, $user);

    return $offer->refresh();
}

/**
 * @return list<Email>
 */
function sentMails(): array
{
    return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage())->values()->all();
}
