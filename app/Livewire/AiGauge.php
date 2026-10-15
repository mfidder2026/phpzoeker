<?php

namespace App\Livewire;

use App\Ai\Budget\AiBudgetGuard;
use App\Ai\Config\AiAgentProfile;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiRunStatus;
use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Support\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * AI-meter in de kopbalk en (compact) onderaan de zijbalk (§15.4, FR-AI5). Laat altijd zien of
 * AI bezig is, hoeveel taken lopen of wachten, het verbruik van het dagbudget en fouten (R10).
 */
class AiGauge extends Component
{
    public bool $compact = false;

    #[On('ai-run-started')]
    #[On('ai-run-finished')]
    public function refreshGauge(): void
    {
        // Lege methode: een Livewire-event of poll rendert de meter opnieuw.
    }

    /**
     * @return Builder<AiRun>
     */
    private function scopedRuns(): Builder
    {
        $user = Access::user();

        return AiRun::query()->when(
            ! Access::isBeheerder(),
            fn (Builder $query) => $query->where('started_by', $user->id ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        /** @var Collection<int, AiRun> $open */
        $open = $this->scopedRuns()->open()->with('starter')->orderBy('queued_at')->limit(8)->get();
        $running = $open->where('status', AiRunStatus::Running);
        $queued = $open->where('status', AiRunStatus::Queued);

        $recentFailure = $this->scopedRuns()
            ->where('status', AiRunStatus::Failed->value)
            ->where('finished_at', '>=', now()->subMinutes(10))
            ->latest('finished_at')
            ->first();

        $budgetGuard = app(AiBudgetGuard::class);
        $profile = AiAgentProfile::active();
        $budget = $budgetGuard->budget($profile);
        $spent = $budgetGuard->spentToday();
        $budgetFraction = $budget > 0 ? min(1.0, $spent / $budget) : 0.0;
        $connectionFailed = $profile === null || (($profile->last_test_result['passed'] ?? true) === false);

        $progressValues = $running->pluck('progress')->filter(fn (?int $progress): bool => $progress !== null);
        $progress = $progressValues->isEmpty() ? null : (int) round((float) $progressValues->avg());

        [$mode, $label] = match (true) {
            $running->isNotEmpty() => ['busy', 'AI bezig · '.$running->count().' '.($running->count() === 1 ? 'taak' : 'taken')],
            $queued->isNotEmpty() => ['queue', $queued->count().' '.($queued->count() === 1 ? 'taak' : 'taken').' in wachtrij'],
            $profile === null => ['offline', 'AI niet ingesteld'],
            $connectionFailed => ['offline', 'AI-verbinding mislukt'],
            $budget > 0 && $budgetFraction >= 1.0 => ['budget', 'Budget op — AI gepauzeerd'],
            $recentFailure !== null => ['error', 'AI-fout — bekijk'],
            default => ['idle', 'AI inactief'],
        };

        return [
            'mode' => $mode,
            'label' => $label,
            'open' => $open,
            'runningCount' => $running->count(),
            'queuedCount' => $queued->count(),
            'progress' => $progress,
            'spent' => $spent,
            'budget' => $budget,
            'budgetFraction' => $budgetFraction,
            'recentFailure' => $recentFailure,
            'activityUrl' => AiRunResource::getUrl(),
            'pollSeconds' => $open->isNotEmpty() ? 2 : 10,
        ];
    }

    public function render(): View
    {
        return view('livewire.ai-gauge', $this->state());
    }
}
