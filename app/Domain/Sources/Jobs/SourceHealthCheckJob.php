<?php

namespace App\Domain\Sources\Jobs;

use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Enums\Role;
use App\Models\User;
use App\Settings\IngestSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dagelijks (FR-B6, §14): een bron die een instelbaar aantal uur niets nieuws oplevert of
 * herhaaldelijk faalt, krijgt een waarschuwing; de beheerder krijgt een melding in de app.
 * Daarnaast worden publicaties die bleven hangen (bijv. budget op) opnieuw in de wachtrij gezet.
 */
class SourceHealthCheckJob implements ShouldQueue
{
    use Queueable;

    public function handle(IngestSettings $settings): void
    {
        $warnings = [];

        foreach (Source::query()->where('enabled', true)->whereIn('type', SourceType::searchable())->get() as $source) {
            $reference = $source->last_new_posting_at ?? $source->created_at;
            $warning = $source->health_warning;

            if ($source->consecutive_failures >= $settings->health_max_failures) {
                $warning ??= "{$source->consecutive_failures}× achter elkaar mislukt.";
            } elseif ($reference !== null && $reference->lt(now()->subHours($settings->health_empty_hours))) {
                $warning = "Al {$settings->health_empty_hours} uur geen nieuwe publicaties.";
            }

            $source->forceFill(['health_warning' => $warning])->save();

            if ($warning !== null) {
                $warnings[] = "{$source->name}: {$warning}";
            }
        }

        if ($warnings !== []) {
            Notification::make()
                ->warning()
                ->title('Bronwaarschuwing')
                ->body(implode("\n", $warnings))
                ->sendToDatabase(User::role(Role::Beheerder->value)->get());
        }

        RawPosting::query()
            ->where('status', RawPostingStatus::Pending->value)
            ->where('received_at', '<', now()->subMinutes(30))
            ->limit(200)
            ->pluck('id')
            ->each(fn (int $id) => ProcessRawPostingJob::dispatch($id));
    }
}
