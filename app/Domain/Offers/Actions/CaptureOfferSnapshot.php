<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OfferSnapshot;
use App\Domain\Offers\Models\OutboundEmail;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Legt bij verzending (of handmatige registratie) vast hoe de aanbieding eruitzag (§11.7, R8):
 * de mail met kopregels en MIME-bericht, een kopie van de bijlagen, de aanbiedingsgegevens, de
 * eisen-matrix, de opdracht van dat moment en de herkomst. SHA-256 over het geheel.
 */
class CaptureOfferSnapshot
{
    /**
     * @param  list<array{path: string, name: string, mime: string, size: int}>  $extraFiles  bijv. geüploade bestanden bij handmatig registreren
     */
    public function handle(Offer $offer, ?OutboundEmail $email, ?User $user, string $kind, array $extraFiles = []): OfferSnapshot
    {
        $offer->loadMissing(['assignment', 'candidate', 'match', 'latestCv', 'organization', 'contact', 'approvedBy']);
        $disk = Storage::disk(OfferSnapshot::DISK);
        $directory = "offers/{$offer->id}/snapshot";
        $disk->makeDirectory($directory);

        $files = [];

        foreach ([...($email->attachments ?? []), ...$extraFiles] as $index => $file) {
            if (! $disk->exists($file['path'])) {
                continue;
            }

            $target = "{$directory}/".($index + 1).'-'.$file['name'];
            $disk->copy($file['path'], $target);
            $content = (string) $disk->get($target);

            $files[] = ['path' => $target, 'name' => $file['name'], 'mime' => $file['mime'], 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }

        $emlPath = null;

        if ($email?->raw_mime !== null) {
            $emlPath = "{$directory}/aanbieding.eml";
            $disk->put($emlPath, $email->raw_mime);
        }

        $assignment = $offer->assignment;
        $cv = $offer->latestCv;

        $attributes = [
            'offer_id' => $offer->id,
            'captured_at' => now()->startOfSecond(),
            'captured_by' => $user?->id,
            'kind' => $kind,
            'email_headers' => $email === null ? null : [
                'from' => trim(($email->from_name ? $email->from_name.' ' : '').'<'.$email->from.'>'),
                'to' => $email->to,
                'cc' => $email->cc ?? [],
                'bcc' => $email->bcc ?? [],
                'subject' => $email->subject,
                'date' => ($email->sent_at ?? now())->toIso8601String(),
                'message_id' => $email->internet_message_id,
                'conversation_id' => $email->conversation_id,
            ],
            'email_html' => $email?->body_html,
            'email_text' => $email?->body_text,
            'eml_path' => $emlPath,
            'attachments' => $files,
            'offer_data' => [
                'channel' => $offer->channel->value,
                'offered_rate' => (string) $offer->offered_rate,
                'candidate' => $offer->candidate_snapshot,
                'via' => $offer->viaName(),
                'organization_id' => $offer->organization_id,
                'contact' => $offer->contact?->only(['name', 'email']),
                'external_reference' => $offer->external_reference,
                'sent_at' => $offer->sent_at?->toIso8601String(),
                'expected_response_at' => $offer->expected_response_at?->toIso8601String(),
            ],
            'assessment' => $offer->match === null ? null : [
                'score' => $offer->match->score,
                'label' => $offer->match->label->value,
                'score_breakdown' => $offer->match->score_breakdown,
                'eisen' => $offer->match->requirementRows(),
                'wensen' => $offer->match->wishRows(),
                'warnings' => $offer->match->warnings,
            ],
            'assignment_data' => [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'reference' => $assignment->reference,
                'client_name' => $assignment->client_name,
                'intermediary_name' => $assignment->intermediary_name,
                'description_text' => $assignment->description_text,
                'summary' => $assignment->summary,
                'fields' => collect($assignment->attributesToArray())->only([
                    'city', 'work_mode', 'hours_min', 'hours_max', 'start_date', 'start_asap', 'end_date', 'duration_months',
                    'max_rate', 'contract_type', 'deadline_at', 'requirements', 'wishes', 'technologies', 'screening',
                ])->map(fn (mixed $value): mixed => is_float($value) || is_int($value) ? (string) $value : $value)->all(),
                'updated_at' => $assignment->updated_at?->toIso8601String(),
            ],
            'provenance' => [
                'profile_version' => $cv?->profile_version,
                'cv_version' => $cv?->version,
                'cv_model' => $cv?->model,
                'cv_prompt_version' => $cv?->prompt_version,
                'mail_model' => $email?->model,
                'mail_prompt_version' => $email?->prompt_version,
                'match_model' => $offer->match?->model,
                'match_prompt_version' => $offer->match?->prompt_version,
                'approved_by' => $offer->approvedBy?->name,
                'sent_by' => $user?->name,
            ],
        ];

        // Door JSON heen en terug, zodat de hash precies over de opgeslagen vorm gaat.
        foreach (['email_headers', 'attachments', 'offer_data', 'assessment', 'assignment_data', 'provenance'] as $key) {
            $attributes[$key] = $attributes[$key] === null ? null : json_decode((string) json_encode($attributes[$key]), true);
        }

        $attributes['sha256'] = OfferSnapshot::computeHash($attributes);

        return OfferSnapshot::query()->create($attributes);
    }
}
