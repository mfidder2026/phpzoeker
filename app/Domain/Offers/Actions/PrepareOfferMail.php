<?php

namespace App\Domain\Offers\Actions;

use App\Ai\Agents\OfferMailWriter;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Enums\OutboundEmailType;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OutboundEmail;
use App\Domain\Offers\Support\OfferMailComposer;
use App\Domain\Offers\Support\PlatformPackage;
use App\Domain\Offers\Support\Recipients;
use App\Models\User;
use App\Settings\MailSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Wizard stap 3 (§11.2, §13): de introductie en bullets laten schrijven, de mail opbouwen uit
 * data en templates, en als concept opslaan. Bij platformindiening (§13.6) wordt het een
 * interne mail aan de CC-lijst met het indieningspakket.
 */
class PrepareOfferMail
{
    /**
     * @var list<array{key: string, label: string, weight: int}>
     */
    public const STEPS = [
        ['key' => 'write', 'label' => 'Mailtekst schrijven', 'weight' => 80],
        ['key' => 'compose', 'label' => 'Mail en bijlagen samenstellen', 'weight' => 20],
    ];

    public function __construct(
        private readonly OfferMailWriter $writer,
        private readonly OfferMailComposer $composer,
        private readonly Recipients $recipients,
        private readonly MailSettings $settings,
        private readonly PromptRepository $prompts,
        private readonly PlatformPackage $package,
        private readonly TransitionOffer $transitions,
    ) {}

    public static function queueRun(Offer $offer, ?User $user): TrackedRun
    {
        return RunTracker::queue(
            task: AiTask::Mail,
            agent: OfferMailWriter::class,
            subject: $offer->assignment,
            startedBy: $user,
            steps: self::STEPS,
            subjectLabel: 'Mailtekst maken — '.$offer->candidate->name.' → '.Str::limit($offer->assignment->title, 60),
            promptVersion: app(PromptRepository::class)->label(OfferMailWriter::PROMPT),
        );
    }

    public function handle(Offer $offer, TrackedRun $run, ?User $user): OutboundEmail
    {
        $offer->loadMissing(['candidate', 'assignment', 'match', 'latestCv', 'organization', 'contact', 'assignmentSource']);
        $cv = $offer->latestCv;

        if ($cv === null || ! $cv->isApproved()) {
            throw new RuntimeException('Keur eerst het CV goed.');
        }

        $platform = $offer->channel === OfferChannel::Platform;
        $run->markRunning();

        try {
            $run->step('write')->begin();
            $text = $this->writer->write(
                $run,
                $offer->assignment,
                $offer->candidate->name,
                (string) ($cv->content['cv_titel'] ?? ''),
                $cv->content,
                $offer->match?->assessment,
                $offer->assignment->language === 'en' ? 'en' : 'nl',
                $platform,
                is_numeric($offer->assignment->submission['max_tekens_per_eis'] ?? null) ? (int) $offer->assignment->submission['max_tekens_per_eis'] : null,
            );
            $run->step('write')->complete();

            $run->step('compose')->begin();
            $email = $platform ? $this->internalPackageEmail($offer, $text) : $this->offerEmail($offer, $text);
            $email->forceFill(['model' => $run->profile?->modelFor(AiTask::Mail), 'prompt_version' => $this->prompts->label(OfferMailWriter::PROMPT)])->save();
            $run->step('compose')->complete();
        } catch (Throwable $exception) {
            $run->fail($exception);

            throw $exception;
        }

        if ($offer->status === OfferStatus::CvInReview) {
            $this->transitions->handle($offer, OfferStatus::MailInReview, $user);
        }

        $run->finish(['outbound_email_id' => $email->id]);

        return $email;
    }

    /**
     * @param  array{intro: string, bullets: list<string>, eis_antwoorden: list<array{id: string, antwoord: string}>}  $text
     */
    private function offerEmail(Offer $offer, array $text): OutboundEmail
    {
        $suggestions = $this->recipients->suggestions($offer);
        $to = array_slice(array_keys($suggestions), 0, 1);
        $body = $this->composer->body($offer, $text['intro'], $text['bullets']);

        return $this->replaceDraft($offer, OutboundEmailType::Offer, [
            'to' => $to,
            'cc' => $this->recipients->defaultCc($offer, $to),
            'bcc' => $this->recipients->defaultBcc(),
            'recipient_origins' => array_intersect_key($suggestions, array_flip($to)),
            'subject' => $this->composer->subject($offer, (string) ($offer->latestCv->content['cv_titel'] ?? '')),
            'body_html' => $body['html'],
            'body_text' => $body['text'],
            'attachments' => $this->attachments($offer),
        ]);
    }

    /**
     * @param  array{intro: string, bullets: list<string>, eis_antwoorden: list<array{id: string, antwoord: string}>}  $text
     */
    private function internalPackageEmail(Offer $offer, array $text): OutboundEmail
    {
        $zip = $this->package->build($offer, $text);
        $to = Recipients::dedupe($this->settings->default_offer_cc);
        $lines = [
            "Indieningspakket voor {$offer->candidate->name} op \"{$offer->assignment->title}\"".($offer->assignment->reference ? " ({$offer->assignment->reference})" : '').'.',
            'Deze opdracht wordt ingediend via '.$offer->viaName().'. In de bijlage: CV, motivatie, antwoorden per eis, tarief en beschikbaarheid.',
            'Na het indienen vult de accountmanager het externe referentienummer in PHP Zoeker in.',
        ];

        return $this->replaceDraft($offer, OutboundEmailType::InternalPackage, [
            'to' => $to,
            'cc' => [],
            'bcc' => [],
            'recipient_origins' => array_fill_keys($to, 'interne cc-lijst'),
            'subject' => "[Intern] Indiening {$offer->candidate->name} – {$offer->assignment->title}",
            'body_html' => '<p>'.implode('</p><p>', array_map('e', $lines)).'</p>',
            'body_text' => implode("\n\n", $lines)."\n",
            'attachments' => [...$this->attachments($offer), $zip],
        ]);
    }

    /**
     * Pdf (en optioneel docx) van het goedgekeurde CV, met de naam uit §12.7.
     *
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    public function attachments(Offer $offer): array
    {
        $cv = $offer->latestCv;
        $files = [];
        $base = "CV {$offer->candidate->name} - Fremen";

        if ($cv?->pdf_path !== null) {
            $files[] = ['path' => $cv->pdf_path, 'name' => "{$base}.pdf", 'mime' => 'application/pdf', 'size' => self::size($cv->pdf_path)];
        }

        if ($cv?->docx_path !== null && ($this->settings->attach_docx || $cv->pdf_path === null)) {
            $files[] = ['path' => $cv->docx_path, 'name' => "{$base}.docx", 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => self::size($cv->docx_path)];
        }

        return $files;
    }

    public static function size(string $path): int
    {
        return Storage::disk('local')->exists($path) ? Storage::disk('local')->size($path) : 0;
    }

    /**
     * Eén concept per soort: een nieuw concept vervangt het vorige (nog niet verzonden) concept.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function replaceDraft(Offer $offer, OutboundEmailType $type, array $attributes): OutboundEmail
    {
        $offer->emails()->where('type', $type->value)->whereIn('status', [OutboundEmailStatus::Concept->value, OutboundEmailStatus::Goedgekeurd->value])->delete();

        return $offer->emails()->create([
            'type' => $type,
            'from' => $this->settings->sender_mailbox,
            'from_name' => $this->settings->sender_name,
            'status' => OutboundEmailStatus::Concept,
            'send_key' => (string) Str::uuid(),
            ...$attributes,
        ]);
    }
}
