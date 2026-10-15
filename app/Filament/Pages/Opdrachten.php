<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Support\PlannedFeaturePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Opdrachten extends PlannedFeaturePage
{
    protected static ?string $slug = 'opdrachten';

    protected static ?string $title = 'Opdrachten';

    protected static ?string $navigationLabel = 'Opdrachten';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::WERK;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static int $phase = 2;

    protected static string $summary = 'Alle opdrachten uit de bronnen, ontdubbeld, met geëxtraheerde eisen, wensen, uren, tarief, locatie en deadline.';

    protected static array $features = [
        'Filters op kandidaat, score, bron, provincie, werkvorm, contractvorm, sector en deadline',
        'Detailpagina met originele tekst, bronlinks en matches per kandidaat',
        'Opdracht handmatig toevoegen (URL of tekst)',
    ];

    protected static function allowedRoles(): array
    {
        return [Role::Beheerder, Role::Accountmanager];
    }
}
