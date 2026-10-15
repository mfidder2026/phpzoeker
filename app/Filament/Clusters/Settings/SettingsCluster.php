<?php

namespace App\Filament\Clusters\Settings;

use App\Ai\Config\AiAgentProfile;
use App\Filament\NavigationGroup;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SettingsCluster extends Cluster
{
    protected static ?string $slug = 'instellingen';

    protected static ?string $navigationLabel = 'Instellingen';

    protected static ?string $clusterBreadcrumb = 'Instellingen';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::BEHEER;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public static function canAccess(): bool
    {
        return Access::isBeheerder();
    }

    /**
     * Rode badge als de laatste AI-verbindingstest is mislukt (FR-AI8).
     */
    public static function getNavigationBadge(): ?string
    {
        $result = AiAgentProfile::active()?->last_test_result;

        return $result !== null && ! ($result['passed'] ?? true) ? '!' : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return static::getNavigationBadge() !== null ? 'AI-verbinding mislukt' : null;
    }
}
