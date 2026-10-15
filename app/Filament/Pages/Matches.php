<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Support\PlannedFeaturePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Matches extends PlannedFeaturePage
{
    protected static ?string $slug = 'matches';

    protected static ?string $title = 'Matches';

    protected static ?string $navigationLabel = 'Matches';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::WERK;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static int $phase = 3;

    protected static string $summary = 'Per opdracht en kandidaat een onderbouwde matchscore met uitleg per eis en wens.';

    protected static array $features = [
        'Prefilter, harde filters en LLM-beoordeling',
        'Labels sterke / mogelijke / zwakke match',
        'Feedback 👍/👎 voor bijsturing',
    ];

    protected static function allowedRoles(): array
    {
        return [Role::Beheerder, Role::Accountmanager, Role::Kandidaat];
    }
}
