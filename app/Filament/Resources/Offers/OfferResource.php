<?php

namespace App\Filament\Resources\Offers;

use App\Domain\Offers\Models\Offer;
use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Offers\Pages\OfferWizard;
use App\Filament\Resources\Offers\Pages\ViewOffer;
use App\Filament\Resources\Offers\Tables\OffersTable;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Aanbiedingsregister (§11.5, FR-AR): elke aanbieding, ook die buiten de app. Een kandidaat
 * ziet alleen de eigen aanbiedingen.
 */
class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static ?string $slug = 'aanbiedingsregister';

    protected static ?string $modelLabel = 'aanbieding';

    protected static ?string $pluralModelLabel = 'aanbiedingen';

    protected static ?string $navigationLabel = 'Aanbiedingsregister';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AANBIEDINGEN;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    public static function canAccess(): bool
    {
        return Access::hasAny([Role::Beheerder, Role::Accountmanager, Role::Reviewer, Role::Kandidaat]);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Access::user();

        return parent::getEloquentQuery()
            ->with(['candidate', 'assignment', 'organization', 'contact', 'assignmentSource', 'sentBy'])
            ->when($user !== null && $user->isKandidaat(), fn (Builder $query) => $query->where('candidate_id', $user->candidate_id ?? 0));
    }

    /**
     * Badge: reacties verlopen (§11.6).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Offer::whereOverdue(static::getEloquentQuery())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Reactie verlopen';
    }

    public static function table(Table $table): Table
    {
        return OffersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOffers::route('/'),
            'view' => ViewOffer::route('/{record}'),
            'wizard' => OfferWizard::route('/{record}/aanbieden'),
        ];
    }
}
