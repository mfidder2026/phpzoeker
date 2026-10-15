<?php

namespace App\Ai\Budget;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiTask;

/**
 * Bewaakt het AI-dagbudget (§18.3). Taken die een gebruiker zelf start (CV, mail,
 * verbindingstest) gaan altijd door; automatische taken pauzeren bij 100%.
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
     * @throws AiProviderException
     */
    public function ensureAllowed(AiTask $task, bool $userInitiated, ?AiAgentProfile $profile = null): void
    {
        if ($userInitiated || $task === AiTask::ConnectionTest) {
            return;
        }

        if ($this->isExhausted($profile)) {
            throw AiProviderException::budgetExceeded();
        }
    }
}
