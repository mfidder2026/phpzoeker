<?php

namespace App\Ai\Budget;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiTask;
use App\Enums\Role;
use App\Models\User;
use App\Settings\MatchingSettings;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Bewaakt het AI-dagbudget (§14, §18.3). Taken die een gebruiker zelf start (CV, mail,
 * verbindingstest) gaan altijd door; automatische taken pauzeren bij 100%. Extractie heeft
 * voorrang op matching: matching pauzeert al wanneer alleen de reserve voor extractie over is.
 */
class AiBudgetGuard
{
    public const WARNING_THRESHOLD = 0.8;

    public function spentToday(): float
    {
        return (float) AiRun::query()->today()->sum('cost_eur');
    }

    public function budget(?AiAgentProfile $profile = null): float
    {
        $profile ??= AiAgentProfile::active();

        return (float) ($profile->daily_budget_eur ?? 0);
    }

    public function usageFraction(?AiAgentProfile $profile = null): float
    {
        $budget = $this->budget($profile);

        return $budget <= 0 ? 0.0 : $this->spentToday() / $budget;
    }

    public function isExhausted(?AiAgentProfile $profile = null): bool
    {
        return $this->budget($profile) > 0 && $this->usageFraction($profile) >= 1.0;
    }

    /**
     * Mag een automatische matchbeoordeling nog? Nee zodra alleen de extractiereserve over is.
     */
    public function allowsMatching(?AiAgentProfile $profile = null): bool
    {
        if ($this->budget($profile) <= 0) {
            return true;
        }

        $reserve = max(0, min(100, app(MatchingSettings::class)->extraction_budget_reserve_percent)) / 100;

        return $this->usageFraction($profile) < 1.0 - $reserve;
    }

    /**
     * @throws AiProviderException
     */
    public function ensureAllowed(AiTask $task, bool $userInitiated, ?AiAgentProfile $profile = null): void
    {
        if ($userInitiated || $task === AiTask::ConnectionTest) {
            return;
        }

        if ($this->isExhausted($profile) || ($task === AiTask::Match && ! $this->allowsMatching($profile))) {
            throw AiProviderException::budgetExceeded();
        }
    }

    /**
     * Eén melding per dag aan de beheerders bij 80% en bij 100% van het dagbudget (§14).
     */
    public function notifyThresholds(?AiAgentProfile $profile = null): void
    {
        $budget = $this->budget($profile);

        if ($budget <= 0) {
            return;
        }

        $fraction = $this->usageFraction($profile);
        $euro = fn (float $amount): string => '€ '.number_format($amount, 2, ',', '.');
        $usage = $euro($this->spentToday()).' van '.$euro($budget).' ('.(int) round($fraction * 100).'%)';

        foreach ([[1.0, 'AI-dagbudget op'], [self::WARNING_THRESHOLD, 'AI-dagbudget bijna op']] as [$threshold, $title]) {
            if ($fraction < $threshold) {
                continue;
            }

            if (Cache::add('ai-budget-melding:'.$threshold.':'.now()->toDateString(), true, now()->endOfDay())) {
                Notification::make()
                    ->warning()
                    ->title($title)
                    ->body($threshold >= 1.0
                        ? "Verbruik vandaag {$usage}. Automatische AI-taken pauzeren tot morgen; CV's en mails gaan door."
                        : "Verbruik vandaag {$usage}. Matching pauzeert eerder dan extractie.")
                    ->sendToDatabase(User::query()->whereHas('roles', fn ($query) => $query->where('name', Role::Beheerder->value))->get());
            }

            break;
        }
    }
}
