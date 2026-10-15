<?php

namespace App\Domain\Sources\Actions;

use App\Ai\Support\SecretRedactor;
use App\Domain\Assignments\Models\AssignmentSource;
use App\Domain\Sources\ConnectorRegistry;
use App\Domain\Sources\Contracts\AcknowledgesPostings;
use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Enums\SourceRunStatus;
use App\Domain\Sources\Jobs\ProcessRawPostingJob;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Domain\Sources\Models\SourceRun;
use App\Settings\IngestSettings;
use DateTimeImmutable;
use Throwable;

/**
 * Eén run van een bron (FR-B3): ophalen, nieuwe publicaties opslaan en in de wachtrij zetten,
 * en bestaande als "opnieuw gezien" markeren. Een fout raakt alleen deze bron.
 */
class RunSource
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly IngestSettings $settings,
    ) {}

    public function handle(Source $source): SourceRun
    {
        $run = SourceRun::query()->create([
            'source_id' => $source->id,
            'status' => SourceRunStatus::Running,
            'started_at' => now(),
            'fetched_count' => 0,
            'new_count' => 0,
            'error_count' => 0,
        ]);
        $source->forceFill(['last_run_at' => now()])->save();

        try {
            $connector = $this->connectors->for($source->type);
            $since = $source->last_success_at !== null ? DateTimeImmutable::createFromInterface($source->last_success_at) : null;

            foreach ($connector->fetch($source, $since) as $data) {
                if ($run->fetched_count >= $this->settings->max_items_per_run) {
                    $run->appendLog("Maximum van {$this->settings->max_items_per_run} publicaties per run bereikt; de rest volgt bij de volgende run.");

                    break;
                }

                $run->fetched_count++;

                try {
                    $posting = RawPosting::query()->firstOrNew(['source_id' => $source->id, 'external_id' => $data->externalId]);

                    if ($posting->exists) {
                        AssignmentSource::query()->where('raw_posting_id', $posting->id)->update(['last_seen_at' => now()]);

                        if ($posting->assignment_id !== null) {
                            $posting->assignment?->forceFill(['last_seen_at' => now()])->save();
                        }

                        // Al opgeslagen maar nog niet gemarkeerd (bijv. na een onderbroken run).
                        if ($connector instanceof AcknowledgesPostings) {
                            $connector->acknowledge($source, $data);
                        }

                        continue;
                    }

                    $posting->fill([
                        'url' => $data->url,
                        'title' => mb_substr($data->title, 0, 255),
                        'body_text' => $data->bodyText,
                        'body_html' => $data->bodyHtml,
                        'meta' => $data->meta,
                        'content_hash' => RawPosting::hashContent($data->title, $data->bodyText),
                        'published_at' => $data->publishedAt,
                        'received_at' => now(),
                        'status' => RawPostingStatus::Pending,
                    ])->save();

                    $run->new_count++;
                    ProcessRawPostingJob::dispatch($posting->id);

                    if ($connector instanceof AcknowledgesPostings) {
                        $connector->acknowledge($source, $data);
                    }
                } catch (Throwable $exception) {
                    $run->error_count++;
                    $run->appendLog('Fout bij "'.mb_substr($data->title, 0, 80).'": '.SecretRedactor::redact($exception->getMessage()));
                }
            }

            $run->forceFill(['status' => SourceRunStatus::Succeeded, 'finished_at' => now()])->save();
            $source->forceFill([
                'last_success_at' => now(),
                'consecutive_failures' => 0,
                'last_new_posting_at' => $run->new_count > 0 ? now() : $source->last_new_posting_at,
                'health_warning' => null,
            ])->save();
        } catch (Throwable $exception) {
            $run->appendLog(SecretRedactor::redact($exception->getMessage()));
            $run->forceFill(['status' => SourceRunStatus::Failed, 'finished_at' => now(), 'error_count' => $run->error_count + 1])->save();

            $failures = $source->consecutive_failures + 1;
            $source->forceFill([
                'consecutive_failures' => $failures,
                'health_warning' => $failures >= $this->settings->health_max_failures
                    ? "{$failures}× achter elkaar mislukt: ".mb_substr(SecretRedactor::redact($exception->getMessage()), 0, 150)
                    : $source->health_warning,
            ])->save();
        }

        return $run;
    }
}
