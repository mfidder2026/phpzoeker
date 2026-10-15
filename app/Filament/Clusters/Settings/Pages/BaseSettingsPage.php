<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Support\Access;
use Filament\Pages\SettingsPage;

abstract class BaseSettingsPage extends SettingsPage
{
    protected static ?string $cluster = SettingsCluster::class;

    public static function canAccess(): bool
    {
        return Access::isBeheerder();
    }

    protected function afterSave(): void
    {
        activity('instellingen')
            ->causedBy(Access::user())
            ->withProperties(['groep' => static::$navigationLabel])
            ->log('Instellingen gewijzigd: '.static::$navigationLabel);
    }
}
