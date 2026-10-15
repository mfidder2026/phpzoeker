<?php

namespace App\Filament\Resources\Assignments;

use App\Domain\Assignments\Models\Assignment;
use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Assignments\Pages\EditAssignment;
use App\Filament\Resources\Assignments\Pages\ListAssignments;
use App\Filament\Resources\Assignments\Pages\ViewAssignment;
use App\Filament\Resources\Assignments\Schemas\AssignmentForm;
use App\Filament\Resources\Assignments\Schemas\AssignmentInfolist;
use App\Filament\Resources\Assignments\Tables\AssignmentsTable;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Opdrachten (§9, §15.5, FR-O1–O7).
 */
class AssignmentResource extends Resource
{
    protected static ?string $model = Assignment::class;

    protected static ?string $slug = 'opdrachten';

    protected static ?string $modelLabel = 'opdracht';

    protected static ?string $pluralModelLabel = 'opdrachten';

    protected static ?string $navigationLabel = 'Opdrachten';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::WERK;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return Access::hasAny([Role::Beheerder, Role::Accountmanager, Role::Reviewer]);
    }

    /**
     * Badge: nieuwe relevante opdrachten vandaag (§15.2).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Assignment::query()->where('is_relevant', true)->where('created_at', '>=', now()->startOfDay())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Nieuwe relevante opdrachten vandaag';
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'client_name', 'intermediary_name', 'reference', 'city'];
    }

    public static function form(Schema $schema): Schema
    {
        return AssignmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AssignmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssignmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssignments::route('/'),
            'view' => ViewAssignment::route('/{record}'),
            'edit' => EditAssignment::route('/{record}/edit'),
        ];
    }
}
