<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Support\PlannedFeaturePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class HandmatigRegistreren extends PlannedFeaturePage
{
    protected static ?string $slug = 'handmatig-registreren';

    protected static ?string $title = 'Handmatig registreren';

    protected static ?string $navigationLabel = 'Handmatig registreren';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AANBIEDINGEN;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static int $phase = 4;

    protected static string $summary = 'Aanbiedingen die buiten de app zijn gedaan vastleggen, zodat het register compleet is.';

    protected static array $features = [
        'Upload van .eml of .msg vult Van, Aan, CC, onderwerp en datum in',
        'Zelfde blokkade op dubbele aanbiedingen',
    ];

    protected static function allowedRoles(): array
    {
        return [Role::Beheerder, Role::Accountmanager];
    }
}
