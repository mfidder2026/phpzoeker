<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Support\PlannedFeaturePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Rapportages extends PlannedFeaturePage
{
    protected static ?string $slug = 'rapportages';

    protected static ?string $title = 'Rapportages';

    protected static ?string $navigationLabel = 'Rapportages';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::INZICHT;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static int $phase = 5;

    protected static string $summary = 'Opdrachten per bron, matches per kandidaat, de funnel en AI-kosten.';

    protected static array $features = [
        'Funnel match → shortlist → aangeboden → intake → geplaatst',
        'Doorlooptijden en slagingspercentage',
    ];

    protected static function allowedRoles(): array
    {
        return [Role::Beheerder, Role::Accountmanager];
    }
}
