<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Support\PlannedFeaturePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Shortlist extends PlannedFeaturePage
{
    protected static ?string $slug = 'shortlist';

    protected static ?string $title = 'Shortlist';

    protected static ?string $navigationLabel = 'Shortlist';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::WERK;

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static int $phase = 3;

    protected static string $summary = 'Kanban van opdracht × kandidaat: Nieuw → Shortlist → In voorbereiding → Verzonden → Reactie → Intake → Geplaatst / Afgewezen.';

    protected static array $features = [
        'Deadline-herinnering bij < 24 uur',
        'Direct doorklikken naar "Aanbieden"',
    ];

    protected static function allowedRoles(): array
    {
        return [Role::Beheerder, Role::Accountmanager];
    }
}
