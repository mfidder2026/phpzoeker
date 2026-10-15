<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Support\PlannedFeaturePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Aanbiedingsregister extends PlannedFeaturePage
{
    protected static ?string $slug = 'aanbiedingsregister';

    protected static ?string $title = 'Aanbiedingsregister';

    protected static ?string $navigationLabel = 'Aanbiedingsregister';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AANBIEDINGEN;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static int $phase = 4;

    protected static string $summary = 'Wie is wanneer en door wie aangeboden, voor welk tarief, met welke verwachte reactie en welke status.';

    protected static array $features = [
        'Kengetallen en snelfilters',
        'Snapshot "Zo zag de aanbieding eruit" (onveranderbaar)',
        'Verwachte reactietijd in werkdagen en opvolgmail',
        'Export naar Excel/CSV',
    ];

    protected static function allowedRoles(): array
    {
        return [Role::Beheerder, Role::Accountmanager, Role::Reviewer, Role::Kandidaat];
    }
}
