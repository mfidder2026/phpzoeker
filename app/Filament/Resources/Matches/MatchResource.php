<?php

namespace App\Filament\Resources\Matches;

use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Models\CandidateMatch;
use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Matches\Pages\ListMatches;
use App\Filament\Resources\Matches\Pages\ViewMatch;
use App\Filament\Resources\Matches\Tables\MatchesTable;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Matches (§10, §15.5): per opdracht en kandidaat een onderbouwde score. Een kandidaat ziet
 * alleen de eigen matches (§5).
 */
class MatchResource extends Resource
{
    protected static ?string $model = CandidateMatch::class;

    protected static ?string $slug = 'matches';

    protected static ?string $modelLabel = 'match';

    protected static ?string $pluralModelLabel = 'matches';

    protected static ?string $navigationLabel = 'Matches';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::WERK;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public static function canAccess(): bool
    {
        return Access::hasAny([Role::Beheerder, Role::Accountmanager, Role::Kandidaat]);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Access::user();

        return parent::getEloquentQuery()
            ->with(['assignment', 'candidate'])
            ->when($user !== null && ! $user->isStaff(), fn (Builder $query) => $query->where('candidate_id', $user->candidate_id ?? 0));
    }

    /**
     * Badge: nieuwe sterke matches vandaag (§15.2).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('label', MatchLabel::Sterk->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'success';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Nieuwe sterke matches vandaag';
    }

    public static function table(Table $table): Table
    {
        return MatchesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatches::route('/'),
            'view' => ViewMatch::route('/{record}'),
        ];
    }
}
