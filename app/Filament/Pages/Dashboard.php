<?php

namespace App\Filament\Pages;

use App\Ai\Budget\AiBudgetGuard;
use App\Ai\Config\AiAgentProfile;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Offers\Models\Offer;
use App\Domain\Sources\Models\Source;
use App\Filament\Resources\Assignments\AssignmentResource;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Matches\MatchResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Startpagina (§15.5): sterke matches, deadlines, aanbiedingen, reacties verlopen, bronstatus
 * en AI-kosten.
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

        $matches = fn () => CandidateMatch::query()
            ->with(['assignment', 'candidate'])
            ->forOpenAssignments()
            ->when($user?->isKandidaat(), fn ($query) => $query->where('candidate_id', $user?->candidate_id));

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
            'newSinceYesterday' => Assignment::query()->where('is_relevant', true)->where('created_at', '>=', now()->subDay()->startOfDay())->count(),
            'deadlineSoon' => Assignment::query()->open()->where('is_relevant', true)->whereBetween('deadline_at', [now(), now()->addDay()])->orderBy('deadline_at')->get(),
            'latestAssignments' => Assignment::query()->open()->where('is_relevant', true)->latest()->limit(5)->get(),
            'sources' => Source::query()->orderByDesc('enabled')->orderBy('name')->get(),
            'strongToday' => $matches()->where('label', MatchLabel::Sterk->value)->where('created_at', '>=', now()->startOfDay())->count(),
            'strongOpen' => $matches()->where('label', MatchLabel::Sterk->value)->whereIn('status', [MatchStatus::Nieuw->value, MatchStatus::Bekeken->value])->orderByDesc('score')->limit(8)->get(),
            'shortlistSoon' => $matches()->where('status', MatchStatus::Shortlist->value)
                ->whereHas('assignment', fn ($query) => $query->whereBetween('deadline_at', [now(), now()->addDay()]))->get(),
            'offersThisWeek' => Offer::query()->sent()->where('sent_at', '>=', now()->startOfWeek())->when($user?->isKandidaat(), fn ($query) => $query->where('candidate_id', $user?->candidate_id))->count(),
            'overdueOffers' => $user?->isStaff() ? Offer::query()->overdue()->with(['candidate', 'assignment', 'organization', 'assignmentSource'])->orderBy('expected_response_at')->limit(8)->get() : collect(),
            'offerUrl' => fn (Offer $offer): string => OfferResource::getUrl('view', ['record' => $offer]),
            'matchUrl' => fn (CandidateMatch $match): string => MatchResource::getUrl('view', ['record' => $match]),
            'assignmentUrl' => fn (Assignment $assignment): string => AssignmentResource::getUrl('view', ['record' => $assignment]),
        ];
    }
}
