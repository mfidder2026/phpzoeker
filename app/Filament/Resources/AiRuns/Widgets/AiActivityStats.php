<?php

namespace App\Filament\Resources\AiRuns\Widgets;

use App\Ai\Budget\AiBudgetGuard;
use App\Ai\Config\AiAgentProfile;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiRunStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AiActivityStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '5s';

    protected function getStats(): array
    {
        $guard = app(AiBudgetGuard::class);
        $profile = AiAgentProfile::active();
        $spent = $guard->spentToday();
        $budget = $guard->budget($profile);
        $fraction = $budget > 0 ? $spent / $budget : 0;

        $today = AiRun::query()->today();
        $finished = AiRun::query()->today()->whereNotNull('started_at')->whereNotNull('finished_at')->get(['started_at', 'finished_at']);
        $averageDuration = $finished->isEmpty() ? null : $finished->avg(fn (AiRun $run): int => (int) $run->durationSeconds());
        $lastTest = $profile?->last_test_result;

        $euro = fn (float $amount): string => '€ '.number_format($amount, 2, ',', '.');

        return [
            Stat::make('Dagbudget', $euro($spent).' van '.$euro($budget))
                ->description(round($fraction * 100).'% verbruikt'.($fraction >= 1 ? ' — automatische AI-taken gepauzeerd' : ($fraction >= 0.8 ? ' — waarschuwing' : '')))
                ->color($fraction >= 1 ? 'danger' : ($fraction >= 0.8 ? 'warning' : 'primary')),
            Stat::make('AI-taken vandaag', (string) (clone $today)->count())
                ->description((clone $today)->where('status', AiRunStatus::Failed->value)->count().' mislukt · '.AiRun::query()->open()->count().' lopend of wachtend'),
            Stat::make('Gemiddelde duur vandaag', $averageDuration === null ? '—' : round($averageDuration, 1).' s'),
            Stat::make('AI-verbinding', match (true) {
                $profile === null => 'Niet ingesteld',
                $lastTest === null => 'Nog niet getest',
                (bool) ($lastTest['passed'] ?? false) => '✓ Geslaagd',
                default => '✕ Mislukt',
            })
                ->description($profile?->last_test_at ? 'Laatst getest '.$profile->last_test_at->format('d-m-Y H:i') : ($profile->name ?? 'Instellingen → AI-agent'))
                ->color($lastTest !== null && ($lastTest['passed'] ?? false) ? 'success' : 'danger'),
        ];
    }
}
