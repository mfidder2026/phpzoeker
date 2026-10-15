<?php

namespace App\Filament\Pages;

use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Offers\Models\Offer;
use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Matches\MatchActions;
use App\Filament\Resources\Matches\MatchResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Shortlist als kanban (§15.5): kaart = opdracht × kandidaat. De kolommen na "Shortlist"
 * (in voorbereiding, verzonden, reactie, intake, geplaatst) komen uit de aanbiedingen.
 */
class Shortlist extends Page
{
    protected string $view = 'filament.pages.shortlist';

    protected static ?string $slug = 'shortlist';

    protected static ?string $title = 'Shortlist';

    protected static ?string $navigationLabel = 'Shortlist';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::WERK;

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    /**
     * Kolommen die je kunt slepen; de rest volgt in fase 4.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'nieuw' => 'Nieuw',
        'shortlist' => 'Shortlist',
        'afgewezen' => 'Afgewezen',
    ];

    /**
     * Kolommen uit de aanbiedingen (fase 4); daar verschuif je via het register.
     *
     * @var array<string, list<string>>
     */
    public const OFFER_COLUMNS = [
        'In voorbereiding' => ['concept', 'cv_in_review', 'mail_in_review', 'wacht_op_kandidaat', 'klaar_voor_verzending'],
        'Verzonden' => ['verzonden', 'geen_reactie'],
        'Reactie' => ['reactie_ontvangen'],
        'Intake' => ['intake_gepland'],
        'Geplaatst' => ['geplaatst'],
    ];

    public ?int $candidateId = null;

    public static function canAccess(): bool
    {
        return Access::hasAny([Role::Beheerder, Role::Accountmanager]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = CandidateMatch::query()->where('status', MatchStatus::Shortlist->value)->forOpenAssignments()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Matches op de shortlist';
    }

    public function getSubheading(): string
    {
        return 'Sleep een kaart naar een andere kolom, of gebruik de knopjes op de kaart. "Nieuw" toont sterke en mogelijke matches die nog niet zijn ingedeeld.';
    }

    public function move(int $matchId, string $column): void
    {
        abort_unless(Access::isStaff(), 403);

        $match = CandidateMatch::query()->with('assignment')->findOrFail($matchId);

        match ($column) {
            'shortlist' => MatchActions::toShortlist($match),
            'afgewezen' => MatchActions::markRejected($match, ['reason' => 'Afgewezen op de shortlist']),
            'nieuw' => $match->setStatus(MatchStatus::Bekeken),
            default => abort(422),
        };

        Notification::make()->success()->title('Verplaatst naar '.self::COLUMNS[$column])->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $base = fn () => CandidateMatch::query()
            ->with(['assignment', 'candidate'])
            ->forOpenAssignments()
            ->when($this->candidateId !== null, fn ($query) => $query->where('candidate_id', $this->candidateId));

        /** @var array<string, Collection<int, CandidateMatch>> $columns */
        $columns = [
            'nieuw' => $base()->promising()->whereIn('status', [MatchStatus::Nieuw->value, MatchStatus::Bekeken->value])->orderByDesc('score')->limit(30)->get(),
            'shortlist' => $base()->where('status', MatchStatus::Shortlist->value)->whereDoesntHave('offers', fn ($query) => $query->where('status', '!=', 'ingetrokken'))->get()
                ->sortBy(fn (CandidateMatch $match): int => $match->assignment->deadline_at?->getTimestamp() ?? PHP_INT_MAX)->values(),
            'afgewezen' => $base()->where('status', MatchStatus::Afgewezen->value)->where('status_changed_at', '>=', now()->subDays(30))->latest('status_changed_at')->limit(30)->get(),
        ];

        return [
            'columns' => $columns,
            'labels' => self::COLUMNS,
            'offerColumns' => collect(self::OFFER_COLUMNS)->map(fn (array $statuses) => Offer::query()
                ->with(['assignment', 'candidate', 'organization', 'assignmentSource'])
                ->whereIn('status', $statuses)
                ->when($this->candidateId !== null, fn ($query) => $query->where('candidate_id', $this->candidateId))
                ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', now()->subDays(60)))
                ->latest('updated_at')->limit(30)->get())->all(),
            'offerUrl' => fn (Offer $offer): string => OfferResource::getUrl($offer->status->isPreparing() ? 'wizard' : 'view', ['record' => $offer]),
            'candidates' => Candidate::query()->orderBy('name')->pluck('name', 'id')->all(),
            'matchUrl' => fn (CandidateMatch $match): string => MatchResource::getUrl('view', ['record' => $match]),
        ];
    }
}
