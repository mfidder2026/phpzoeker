<?php

namespace App\Domain\Offers\Jobs;

use App\Domain\Offers\Models\Offer;
use App\Domain\Relations\Models\Organization;
use App\Enums\Role;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nachtelijk (02:30, §11.9): gemiddelde reactietijd per organisatie over de laatste 12 maanden
 * (vanaf 3 metingen). Wijkt die meer dan 2 werkdagen af van de standaard, dan krijgt de beheerder
 * een voorstel om de standaard aan te passen.
 */
class ComputeResponseStatsJob implements ShouldQueue
{
    use Queueable;

    public const MIN_SAMPLES = 3;

    public const SUGGEST_DIFFERENCE = 2;

    public function handle(): void
    {
        $stats = Offer::query()
            ->whereNotNull('organization_id')
            ->whereNotNull('response_time_workdays')
            ->where('sent_at', '>=', now()->subMonths(12))
            ->selectRaw('organization_id, avg(response_time_workdays) as average, count(*) as samples')
            ->groupBy('organization_id')
            ->get();

        $suggestions = [];

        foreach ($stats as $row) {
            $organization = Organization::query()->find((int) $row->getAttribute('organization_id'));

            if ($organization === null) {
                continue;
            }

            $samples = (int) $row->getAttribute('samples');
            $average = $samples >= self::MIN_SAMPLES ? round((float) $row->getAttribute('average'), 1) : null;

            $organization->forceFill(['measured_avg_response_days' => $average, 'measured_sample_size' => $samples])->save();

            if ($average !== null && $organization->default_response_term_days !== null && abs($average - $organization->default_response_term_days) > self::SUGGEST_DIFFERENCE) {
                $suggestions[] = "{$organization->name}: gemeten ".str_replace('.', ',', (string) $average)." werkdagen, standaard {$organization->default_response_term_days}.";
            }
        }

        if ($suggestions !== []) {
            Notification::make()
                ->info()
                ->title('Reactietermijnen bijstellen?')
                ->body(implode("\n", $suggestions))
                ->sendToDatabase(User::query()->whereHas('roles', fn ($query) => $query->where('name', Role::Beheerder->value))->get());
        }
    }
}
