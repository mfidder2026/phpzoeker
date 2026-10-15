<?php

namespace App\Domain\Assignments\Actions;

use App\Ai\Agents\AlertMailSplitter;
use App\Ai\Agents\AssignmentExtractor;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Ai\Support\SecretRedactor;
use App\Ai\Tools\FetchPageTool;
use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Events\AssignmentIngested;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Assignments\Models\AssignmentSource;
use App\Domain\Assignments\Support\AssignmentNormalizer;
use App\Domain\Geo\Geocoder;
use App\Domain\Relations\Actions\ResolveOrganization;
use App\Domain\Relations\Enums\OrganizationType;
use App\Domain\Relations\Models\Contact;
use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Jobs\ProcessRawPostingJob;
use App\Domain\Sources\Models\RawPosting;
use App\Settings\IngestSettings;
use Illuminate\Support\Str;
use Throwable;

/**
 * Van ruwe publicatie naar opdracht (§6 stap 2–4): opsplitsen van alert-mails, exacte
 * ontdubbeling, relevantie-voorfilter, volledige tekst ophalen, extractie, normalisatie,
 * geocodering en ontdubbeling over bronnen heen.
 */
class ProcessRawPosting
{
    /**
     * @var list<array{key: string, label: string, weight: int, optional?: bool}>
     */
    public const STEPS = [
        ['key' => 'fetch', 'label' => 'Volledige opdrachtpagina lezen', 'weight' => 10, 'optional' => true],
        ['key' => 'extract', 'label' => 'Eisen, wensen en kenmerken extraheren', 'weight' => 75],
        ['key' => 'process', 'label' => 'Normaliseren, geocoderen en ontdubbelen', 'weight' => 15],
    ];

    public function __construct(
        private readonly IngestSettings $settings,
        private readonly AssignmentExtractor $extractor,
        private readonly AlertMailSplitter $splitter,
        private readonly AssignmentNormalizer $normalizer,
        private readonly FindDuplicate $duplicates,
        private readonly ResolveOrganization $organizations,
        private readonly Geocoder $geocoder,
        private readonly FetchPageTool $pages,
        private readonly PromptRepository $prompts,
    ) {}

    public function handle(RawPosting $posting, ?TrackedRun $run = null): ?Assignment
    {
        if ($posting->status !== RawPostingStatus::Pending) {
            return $posting->assignment;
        }

        try {
            if (($posting->meta['kind'] ?? null) === 'alert_mail') {
                $this->split($posting);

                return null;
            }

            $existing = RawPosting::query()
                ->where('content_hash', $posting->content_hash)
                ->whereKeyNot($posting->getKey())
                ->whereNotNull('assignment_id')
                ->first();

            // Regel 1 (exact): dezelfde tekst is al verwerkt → alleen de bron koppelen.
            if ($existing?->assignment !== null) {
                $this->attachSource($existing->assignment, $posting, null);
                $this->markDone($posting, $existing->assignment, RawPostingStatus::Duplicate);
                $this->closeRun($run, ['assignment_id' => $existing->assignment->id, 'dubbel' => true]);

                return $existing->assignment;
            }

            if (! $this->looksRelevant($posting->title.' '.$posting->body_text)) {
                $assignment = $this->storeIrrelevant($posting, 'Geen termen voor softwareontwikkeling gevonden (voorfilter, geen AI-aanroep).');
                $this->closeRun($run, ['assignment_id' => $assignment->id, 'relevant' => false]);

                return $assignment;
            }

            $run ??= RunTracker::start(
                task: AiTask::Extract,
                agent: AssignmentExtractor::class,
                subject: $posting,
                steps: self::STEPS,
                subjectLabel: 'Opdracht extraheren — '.Str::limit($posting->title, 80),
                promptVersion: $this->prompts->label(AssignmentExtractor::PROMPT),
            );

            if ($run->run->status->isOpen() && $run->run->started_at === null) {
                $run->markRunning();
            }

            $incomplete = $this->enrich($posting, $run);

            $step = $run->step('extract')->begin();
            $data = $this->extractor->extract($run, $posting->title, (string) $posting->body_text, userInitiated: $run->run->started_by !== null);
            $step->complete();

            $step = $run->step('process')->begin();
            $assignment = $this->store($posting, $data, $incomplete, $run);
            $step->complete();

            $run->finish(['assignment_id' => $assignment->id, 'relevant' => $assignment->is_relevant]);

            return $assignment;
        } catch (AiProviderException $exception) {
            // Budget op of provider onbereikbaar: blijft "te verwerken" en wordt later opnieuw opgepakt.
            $posting->forceFill(['processing_error' => SecretRedactor::redact($exception->getMessage())])->save();
            $this->failRun($run, $exception);

            return null;
        } catch (Throwable $exception) {
            $posting->forceFill([
                'status' => RawPostingStatus::Failed,
                'processing_error' => SecretRedactor::redact(mb_substr($exception->getMessage(), 0, 1000)),
                'processed_at' => now(),
            ])->save();
            $this->failRun($run, $exception);

            return null;
        }
    }

    /**
     * Voorfilter op titel en tekst (FR-O4, §18.3): woorden uit de instellingen, op woordgrenzen.
     */
    public function looksRelevant(string $text): bool
    {
        $text = mb_strtolower($text);

        foreach ($this->settings->relevance_keywords as $keyword) {
            $keyword = mb_strtolower(trim($keyword));

            if ($keyword !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').'(?![\p{L}\p{N}])/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function split(RawPosting $posting): void
    {
        $run = RunTracker::start(
            task: AiTask::Extract,
            agent: AlertMailSplitter::class,
            subject: $posting,
            steps: [['key' => 'split', 'label' => 'Alert-mail opsplitsen in opdrachten', 'weight' => 100]],
            subjectLabel: 'Alert-mail opsplitsen — '.Str::limit($posting->title, 80),
            promptVersion: $this->prompts->label(AlertMailSplitter::PROMPT),
        );

        try {
            $step = $run->step('split')->begin();
            $items = $this->splitter->split($run, $posting->title, (string) $posting->body_text);
            $step->complete();
        } catch (Throwable $exception) {
            $run->fail($exception);

            throw $exception;
        }

        foreach ($items as $index => $item) {
            $child = RawPosting::query()->firstOrCreate(
                ['source_id' => $posting->source_id, 'external_id' => mb_substr($posting->external_id, 0, 480).'#'.($index + 1)],
                [
                    'parent_id' => $posting->id,
                    'url' => $item['url'],
                    'title' => mb_substr($item['titel'], 0, 255),
                    'body_text' => $item['tekst'],
                    'meta' => [...($posting->meta ?? []), 'kind' => 'alert_item'],
                    'content_hash' => RawPosting::hashContent($item['titel'], $item['tekst']),
                    'published_at' => $posting->published_at,
                    'received_at' => now(),
                    'status' => RawPostingStatus::Pending,
                ],
            );

            if ($child->wasRecentlyCreated) {
                ProcessRawPostingJob::dispatch($child->id);
            }
        }

        $run->finish(['opdrachten' => count($items)]);
        $posting->forceFill(['status' => RawPostingStatus::Split, 'processed_at' => now()])->save();
    }

    /**
     * Haalt de volledige openbare pagina op als de publicatie alleen een samenvatting bevat,
     * alleen op het domein van de bron (of bij handmatige invoer: het domein van de URL).
     */
    private function enrich(RawPosting $posting, TrackedRun $run): bool
    {
        $step = $run->step('fetch');

        if ($posting->url === null || mb_strlen((string) $posting->body_text) >= $this->settings->full_text_min_chars) {
            $step->skip();

            return false;
        }

        $urlHost = strtolower((string) parse_url($posting->url, PHP_URL_HOST));
        $allowed = array_values(array_filter([$posting->source->hostname(), ($posting->meta['kind'] ?? null) === 'manual_url' ? $urlHost : null]));

        if ($allowed === []) {
            $step->skip();

            return true;
        }

        $step->begin($posting->url);

        try {
            $page = $this->pages->fetch($posting->url, $allowed);
            $posting->forceFill([
                'body_text' => $page['text'],
                'meta' => [...($posting->meta ?? []), 'full_text_fetched' => true],
            ])->save();
            $step->complete();

            return false;
        } catch (Throwable $exception) {
            $step->failStep('Volledige tekst niet beschikbaar: '.$exception->getMessage());

            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $data  Bijlage B
     */
    private function store(RawPosting $posting, array $data, bool $incomplete, TrackedRun $run): Assignment
    {
        $attributes = $this->normalizer->normalize($data, (string) $posting->body_text);

        if ($attributes['title'] === '') {
            $attributes['title'] = mb_substr($posting->title, 0, 255);
        }

        $client = $this->organizations->handle($attributes['client_name'], OrganizationType::Eindklant);
        $intermediary = $this->organizations->handle($attributes['intermediary_name'], OrganizationType::Intermediair);
        $attributes['client_organization_id'] = $client?->id;
        $attributes['intermediary_organization_id'] = $intermediary->id ?? $posting->source->organization_id;

        if ($attributes['city'] !== null && ($geo = $this->geocoder->geocode($attributes['city'])) !== null) {
            $attributes['lat'] = $geo['lat'];
            $attributes['lng'] = $geo['lng'];
            $attributes['province'] ??= $geo['province'];
        }

        $duplicate = $this->duplicates->handle($attributes);

        if ($duplicate['match'] !== null) {
            $assignment = $this->merge($duplicate['match'], $attributes);
            $this->attachSource($assignment, $posting, $data['contact'] ?? null);
            $this->markDone($posting, $assignment, RawPostingStatus::Processed);
            AssignmentIngested::dispatch($assignment, false);

            return $assignment;
        }

        $deadline = $attributes['deadline_at'];
        $assignment = Assignment::query()->create([
            ...$attributes,
            'dedupe_group_id' => (string) Str::uuid(),
            'description_text' => $posting->body_text,
            'is_incomplete' => $incomplete,
            'relevance_reason' => $attributes['is_relevant'] ? null : 'Volgens de extractie geen softwareontwikkeling.',
            'possible_duplicate_of_id' => $duplicate['possible']?->id,
            'status' => $deadline !== null && $deadline->isPast() ? AssignmentStatus::Gesloten : AssignmentStatus::Nieuw,
            'closed_at' => $deadline !== null && $deadline->isPast() ? now() : null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'extraction_model' => $run->run->refresh()->model,
            'extraction_prompt_version' => $this->prompts->label(AssignmentExtractor::PROMPT),
        ]);

        $this->attachSource($assignment, $posting, $data['contact'] ?? null);
        $this->markDone($posting, $assignment, RawPostingStatus::Processed);
        AssignmentIngested::dispatch($assignment, true);

        return $assignment;
    }

    /**
     * Vult ontbrekende velden van een bestaande opdracht aan, behalve vergrendelde velden (§9.1).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function merge(Assignment $assignment, array $attributes): Assignment
    {
        $updates = ['last_seen_at' => now()];

        foreach ($attributes as $field => $value) {
            if ($value === null || $value === [] || $assignment->isFieldLocked($field) || in_array($field, ['title', 'is_relevant'], true)) {
                continue;
            }

            $current = $assignment->getAttribute($field);

            if ($current === null || $current === [] || $current === 'onbekend') {
                $updates[$field] = $value;
            }
        }

        $assignment->fill($updates)->save();

        return $assignment;
    }

    /**
     * Koppelt de bron aan de opdracht. Een contactadres wordt alleen overgenomen als het
     * letterlijk in de publicatie staat (R5): ontvangers komen nooit uit modeltekst.
     *
     * @param  array<string, mixed>|null  $contact
     */
    private function attachSource(Assignment $assignment, RawPosting $posting, ?array $contact): void
    {
        $text = (string) $posting->body_text.' '.(string) $posting->body_html;
        $email = is_string($contact['email'] ?? null) && filter_var($contact['email'], FILTER_VALIDATE_EMAIL) && str_contains($text, $contact['email']) ? $contact['email'] : null;
        $name = $email !== null && is_string($contact['naam'] ?? null) ? $contact['naam'] : null;
        $phone = $email !== null && is_string($contact['telefoon'] ?? null) && str_contains($text, $contact['telefoon']) ? $contact['telefoon'] : null;

        $contactRecord = $email !== null
            ? Contact::query()->firstOrCreate(['email' => $email], ['name' => $name, 'phone' => $phone, 'organization_id' => $assignment->intermediary_organization_id ?? $assignment->client_organization_id])
            : null;

        AssignmentSource::query()->updateOrCreate(
            ['assignment_id' => $assignment->id, 'raw_posting_id' => $posting->id],
            [
                'source_id' => $posting->source_id,
                'url' => $posting->url,
                'intermediary_name' => $assignment->intermediary_name ?? $posting->source->organization?->name,
                'organization_id' => $assignment->intermediary_organization_id ?? $posting->source->organization_id,
                'contact_id' => $contactRecord?->id,
                'contact_name' => $name,
                'contact_email' => $email,
                'contact_phone' => $phone,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        $assignment->forceFill(['last_seen_at' => now()])->save();
    }

    private function storeIrrelevant(RawPosting $posting, string $reason): Assignment
    {
        $assignment = Assignment::query()->create([
            'dedupe_group_id' => (string) Str::uuid(),
            'title' => mb_substr($posting->title, 0, 255),
            'description_text' => $posting->body_text,
            'is_relevant' => false,
            'relevance_reason' => $reason,
            'is_incomplete' => true,
            'status' => AssignmentStatus::Nieuw,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->attachSource($assignment, $posting, null);
        $this->markDone($posting, $assignment, RawPostingStatus::Irrelevant);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function closeRun(?TrackedRun $run, array $result): void
    {
        if ($run === null) {
            return;
        }

        if ($run->run->started_at === null) {
            $run->markRunning();
        }

        $run->finish($result);
    }

    private function failRun(?TrackedRun $run, Throwable $exception): void
    {
        if ($run !== null && $run->run->status->isOpen()) {
            $run->fail($exception);
        }
    }

    private function markDone(RawPosting $posting, Assignment $assignment, RawPostingStatus $status): void
    {
        $posting->forceFill([
            'status' => $status,
            'assignment_id' => $assignment->id,
            'processed_at' => now(),
            'processing_error' => null,
        ])->save();
    }
}
