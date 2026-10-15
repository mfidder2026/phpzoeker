<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Support\PlannedFeaturePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Bronnen extends PlannedFeaturePage
{
    protected static ?string $slug = 'bronnen';

    protected static ?string $title = 'Bronnen';

    protected static ?string $navigationLabel = 'Bronnen';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::BEHEER;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static int $phase = 2;

    protected static string $summary = 'Bronnen beheren: alerts-mailbox, feeds, toegestane publieke pagina\'s en handmatige invoer.';

    protected static array $features = [
        'ToS-check verplicht voordat een bron aan kan',
        'Run-log en gezondheidscheck',
        'Knop "Test run"',
    ];

    protected static function allowedRoles(): array
    {
        return [Role::Beheerder];
    }
}
