<?php

namespace App\Filament\Resources\Candidates;

use App\Domain\Candidates\Models\Candidate;
use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Candidates\Pages\CreateCandidate;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Filament\Resources\Candidates\RelationManagers\ProfilesRelationManager;
use App\Filament\Resources\Candidates\Schemas\CandidateForm;
use App\Filament\Resources\Candidates\Tables\CandidatesTable;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CandidateResource extends Resource
{
    protected static ?string $model = Candidate::class;

    protected static ?string $slug = 'kandidaten';

    protected static ?string $modelLabel = 'kandidaat';

    protected static ?string $pluralModelLabel = 'kandidaten';

    protected static ?string $navigationLabel = 'Kandidaten';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::MENSEN;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return Access::hasAny([Role::Beheerder, Role::Accountmanager, Role::Reviewer, Role::Kandidaat]);
    }

    /**
     * Een kandidaat ziet alleen zijn eigen kandidaatpagina (§15.2). Verwijderde kandidaten zijn
     * alleen voor de beheerder zichtbaar (filter "Verwijderd") om ze terug te zetten.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = Access::user();

        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->when(! Access::isBeheerder(), fn (Builder $query) => $query->whereNull('deleted_at'))
            ->when($user?->isKandidaat(), fn (Builder $query) => $query->whereKey($user->candidate_id ?? 0));
    }

    /**
     * Subitems per kandidaat onder "Kandidaten" (§15.2).
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $items = Access::user()?->isKandidaat() ? [] : parent::getNavigationItems();

        $candidates = Candidate::query()
            ->when(Access::user()?->isKandidaat(), fn (Builder $query) => $query->whereKey(Access::user()->candidate_id ?? 0))
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($candidates as $index => $candidate) {
            $url = static::getUrl('edit', ['record' => $candidate]);

            $items[] = NavigationItem::make($candidate->name)
                ->group(static::getNavigationGroup())
                ->parentItem(Access::user()?->isKandidaat() ? null : static::$navigationLabel)
                ->icon(Access::user()?->isKandidaat() ? Heroicon::OutlinedUser : null)
                ->sort(static::getNavigationSort() + $index + 1)
                ->isActiveWhen(fn (): bool => request()->routeIs(static::getRouteBaseName().'.edit') && (string) request()->route('record') === (string) $candidate->getKey())
                ->url($url);
        }

        return $items;
    }

    public static function form(Schema $schema): Schema
    {
        return CandidateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CandidatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProfilesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCandidates::route('/'),
            'create' => CreateCandidate::route('/create'),
            'edit' => EditCandidate::route('/{record}/edit'),
        ];
    }
}
