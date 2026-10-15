<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Support\PlannedFeaturePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Relaties extends PlannedFeaturePage
{
    protected static ?string $slug = 'relaties';

    protected static ?string $title = 'Relaties';

    protected static ?string $navigationLabel = 'Relaties';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::MENSEN;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static int $phase = 4;

    protected static string $summary = 'Opdrachtgevers, intermediairs en contactpersonen met standaard en gemeten reactietijden.';

    protected static array $features = [
        'Alle aanbiedingen per relatie',
        'Samenvoegen van dubbele organisaties',
    ];

    protected static function allowedRoles(): array
    {
        return [Role::Beheerder, Role::Accountmanager];
    }
}
