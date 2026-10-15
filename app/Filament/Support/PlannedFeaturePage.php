<?php

namespace App\Filament\Support;

use App\Enums\Role;
use Filament\Pages\Page;

/**
 * Menu-item voor een onderdeel dat in een latere fase wordt gebouwd (§22, fase 1:
 * "nog niet gebouwde onderdelen tonen 'Volgt in fase x'").
 */
abstract class PlannedFeaturePage extends Page
{
    protected string $view = 'filament.pages.planned-feature';

    protected static int $phase = 2;

    protected static string $summary = '';

    /**
     * @var list<string>
     */
    protected static array $features = [];

    /**
     * Rollen die dit onderdeel straks mogen zien.
     *
     * @return list<Role>
     */
    protected static function allowedRoles(): array
    {
        return [Role::Beheerder, Role::Accountmanager];
    }

    public static function canAccess(): bool
    {
        return Access::hasAny(static::allowedRoles());
    }

    public function getSubheading(): string
    {
        return 'Volgt in fase '.static::$phase;
    }

    /**
     * @return array{phase: int, summary: string, features: list<string>}
     */
    protected function getViewData(): array
    {
        return [
            'phase' => static::$phase,
            'summary' => static::$summary,
            'features' => static::$features,
        ];
    }
}
