<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Offers\Enums\OfferActivityType;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Enums\OutboundEmailType;
use App\Domain\Offers\Exceptions\DuplicateOfferException;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OfferSnapshot;
use App\Domain\Offers\Support\EmlReader;
use App\Domain\Offers\Support\OfferMailComposer;
use App\Domain\Offers\Support\ResponseTerm;
use App\Domain\Relations\Actions\ResolveOrganization;
use App\Domain\Relations\Enums\OrganizationType;
use App\Domain\Relations\Models\Contact;
use App\Domain\Relations\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Registreert een aanbieding die buiten de app is gedaan (§11.8): telefonisch, vanuit Outlook
 * of via een platform. Een geüploade `.eml` vult kopregels en bijlagen; de snapshot wordt
 * gemarkeerd als "handmatig vastgelegd".
 */
class RecordManualOffer
{
    public function __construct(
        private readonly CreateOffer $creator,
        private readonly ResolveOrganization $organizations,
        private readonly ResponseTerm $responseTerm,
        private readonly TransitionOffer $transitions,
        private readonly CaptureOfferSnapshot $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $data  formuliervelden: candidate_id, assignment_id of new_title/new_client/new_reference, organization_id of organization_name, contact_name, contact_email, channel, sent_at, offered_rate, sent_by, response_term_days, status, note, eml_path en cv_path (paden op de lokale disk)
     *
     * @throws DuplicateOfferException
     */
    public function handle(array $data, User $user): Offer
    {
        $candidate = Candidate::query()->findOrFail((int) $data['candidate_id']);
        $eml = filled($data['eml_path'] ?? null) ? EmlReader::read(Storage::disk('local')->path((string) $data['eml_path'])) : null;
        $assignment = $this->assignment($data, $eml);

        CreateOffer::ensureNotOffered($candidate, $assignment);

        return DB::transaction(function () use ($data, $user, $candidate, $assignment, $eml): Offer {
            $organization = filled($data['organization_id'] ?? null)
                ? Organization::query()->find((int) $data['organization_id'])
                : (filled($data['organization_name'] ?? null) ? $this->organizations->handle((string) $data['organization_name'], OrganizationType::Intermediair) : null);

            $contactEmail = $data['contact_email'] ?? ($eml['to'][0] ?? null);
            $contact = filled($contactEmail) || filled($data['contact_name'] ?? null)
                ? Contact::query()->firstOrCreate(
                    ['email' => filled($contactEmail) ? mb_strtolower((string) $contactEmail) : null, 'organization_id' => $organization?->id],
                    ['name' => $data['contact_name'] ?? null, 'last_used_at' => now()],
                )
                : null;

            $sentAt = CarbonImmutable::parse((string) $data['sent_at'], ResponseTerm::TIMEZONE);
            $offer = $this->creator->handle($assignment, $candidate, $user, channel: OfferChannel::from((string) $data['channel']), attributes: [
                'is_manual' => true,
                'organization_id' => $organization?->id,
                'contact_id' => $contact?->id,
                'offered_rate' => $data['offered_rate'],
                'sent_at' => $sentAt,
                'sent_by' => $data['sent_by'],
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            $offer->forceFill(['status' => OfferStatus::Verzonden]);
            $this->responseTerm->apply($offer, filled($data['response_term_days'] ?? null) ? (int) $data['response_term_days'] : null, filled($data['response_term_days'] ?? null) ? 'Ingevuld bij handmatig registreren' : null);
            $offer->save();

            TransitionOffer::record($offer, OfferActivityType::Sent, $user, ['text' => 'Handmatig geregistreerd: aangeboden op '.$sentAt->format('d-m-Y H:i').' ('.$offer->channel->getLabel().')'], $sentAt);

            if (filled($data['note'] ?? null)) {
                TransitionOffer::record($offer, OfferActivityType::Note, $user, ['text' => (string) $data['note']]);
            }

            $files = $this->storeFiles($offer, $data, $eml);
            $email = $eml === null ? null : $offer->emails()->create([
                'type' => OutboundEmailType::Offer,
                'from' => $eml['from'] ?? '',
                'to' => $eml['to'],
                'cc' => $eml['cc'],
                'bcc' => [],
                'subject' => $eml['subject'],
                'body_html' => $eml['html'] ?? OfferWorkflow::textToHtml($eml['text']),
                'body_text' => $eml['text'],
                'attachments' => $files['attachments'],
                'status' => OutboundEmailStatus::Verzonden,
                'send_key' => (string) Str::uuid(),
                'provider' => 'handmatig',
                'internet_message_id' => $eml['message_id'],
                'conversation_id' => $eml['message_id'],
                'raw_mime' => (string) Storage::disk('local')->get((string) ($data['eml_path'] ?? '')),
                'approved_by' => $user->id,
                'approved_at' => now(),
                'sent_by' => $data['sent_by'],
                'sent_at' => $eml['date'] ?? $sentAt,
            ]);

            $this->snapshots->handle($offer->refresh(), $email, $user, OfferSnapshot::KIND_MANUAL, $email === null ? $files['attachments'] : $files['extra']);

            $status = OfferStatus::tryFrom((string) ($data['status'] ?? ''));

            if ($status !== null && $status !== OfferStatus::Verzonden && OfferStatus::Verzonden->canTransitionTo($status)) {
                $this->transitions->handle($offer, $status, $user, 'Bij handmatig registreren');
            } else {
                $offer->match?->setStatus(MatchStatus::Aangeboden);
            }

            if (in_array($assignment->status, [AssignmentStatus::Nieuw, AssignmentStatus::Gematcht, AssignmentStatus::Shortlist], true)) {
                $assignment->forceFill(['status' => AssignmentStatus::Aangeboden])->save();
            }

            return $offer->refresh();
        });
    }

    /**
     * Bestaande opdracht, of in één stap een minimale opdracht (§11.8).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $eml
     */
    private function assignment(array $data, ?array $eml): Assignment
    {
        if (filled($data['assignment_id'] ?? null)) {
            return Assignment::query()->findOrFail((int) $data['assignment_id']);
        }

        return Assignment::query()->create([
            'dedupe_group_id' => (string) Str::uuid(),
            'title' => (string) ($data['new_title'] ?? $eml['subject'] ?? 'Handmatig geregistreerde opdracht'),
            'client_name' => $data['new_client'] ?? null,
            'reference' => $data['new_reference'] ?? null,
            'description_text' => $eml['text'] ?? null,
            'is_relevant' => true,
            'status' => AssignmentStatus::Aangeboden,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Kopieert de bijlagen uit de .eml en het geüploade CV naar de map van de aanbieding.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $eml
     * @return array{attachments: list<array{path: string, name: string, mime: string, size: int}>, extra: list<array{path: string, name: string, mime: string, size: int}>}
     */
    private function storeFiles(Offer $offer, array $data, ?array $eml): array
    {
        $disk = Storage::disk('local');
        $directory = "offers/{$offer->id}/manual";
        $attachments = [];
        $extra = [];

        foreach ($eml['attachments'] ?? [] as $index => $attachment) {
            $name = OfferMailComposer::safeFileName((string) $attachment['name']);
            $path = "{$directory}/".($index + 1)."-{$name}";
            $disk->put($path, (string) $attachment['content']);
            $attachments[] = ['path' => $path, 'name' => $name, 'mime' => (string) $attachment['mime'], 'size' => strlen((string) $attachment['content'])];
        }

        if (filled($data['cv_path'] ?? null) && $disk->exists((string) $data['cv_path'])) {
            $name = OfferMailComposer::safeFileName(basename((string) $data['cv_path']));
            $path = "{$directory}/cv-{$name}";
            $disk->copy((string) $data['cv_path'], $path);
            $extra[] = ['path' => $path, 'name' => $name, 'mime' => (string) ($disk->mimeType($path) ?: 'application/octet-stream'), 'size' => $disk->size($path)];
        }

        return ['attachments' => array_merge($attachments, $eml === null ? $extra : []), 'extra' => $extra];
    }
}
