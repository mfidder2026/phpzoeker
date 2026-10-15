<?php

namespace App\Filament\Pages;

use App\Ai\Budget\AiBudgetGuard;
use App\Ai\Config\AiAgentProfile;
use App\Domain\Candidates\Models\Candidate;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Startpagina (§15.5). Tegels voor matches, deadlines en aanbiedingen worden gevuld vanaf
 * fase 3 en 4; kandidaten en AI-verbruik zijn er nu al.
 */
class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -10;

    public function getTitle(): string
    {
        return 'Dashboard';
    }

    public function getHeading(): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Access::user();
        $budget = app(AiBudgetGuard::class);
        $profile = AiAgentProfile::active();

        /** @var Collection<int, Candidate> $candidates */
        $candidates = Candidate::query()
            ->with(['activeProfile', 'absences'])
            ->when($user?->isKandidaat(), fn ($query) => $query->whereKey($user?->candidate_id))
            ->orderBy('name')
            ->get();

        $hour = (int) now()->format('G');

        return [
            'greeting' => match (true) {
                $hour < 12 => 'Goedemorgen',
                $hour < 18 => 'Goedemiddag',
                default => 'Goedenavond',
            },
            'firstName' => explode(' ', (string) $user?->name)[0],
            'today' => now()->settings(['locale' => 'nl'])->translatedFormat('l j F Y'),
            'candidates' => $candidates,
            'candidateUrl' => fn (Candidate $candidate): string => CandidateResource::getUrl('edit', ['record' => $candidate]),
            'spentToday' => $budget->spentToday(),
            'budget' => $budget->budget($profile),
            'aiProfile' => $profile,
            'isStaff' => Access::isStaff(),
        ];
    }
}
