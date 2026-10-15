<?php

namespace App\Filament\Resources\Sources;

use App\Domain\Sources\Models\Source;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\RelationManagers\RunsRelationManager;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Filament\Resources\Sources\Tables\SourcesTable;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Bronnen beheren (§8, FR-B1–B7). Alleen voor de beheerder.
 */
class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static ?string $slug = 'bronnen';

    protected static ?string $modelLabel = 'bron';

    protected static ?string $pluralModelLabel = 'bronnen';

    protected static ?string $navigationLabel = 'Bronnen';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::BEHEER;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return Access::isBeheerder();
    }

    /**
     * Rode badge: bronnen met een fout of waarschuwing (FR-UI2).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Source::query()->where('enabled', true)
            ->where(fn ($query) => $query->whereNotNull('health_warning')->orWhere('consecutive_failures', '>', 0))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Bronnen met een fout of waarschuwing';
    }

    public static function form(Schema $schema): Schema
    {
        return SourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [RunsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSources::route('/'),
            'create' => CreateSource::route('/create'),
            'edit' => EditSource::route('/{record}/edit'),
        ];
    }
}
