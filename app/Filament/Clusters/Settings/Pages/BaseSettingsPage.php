<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Support\Access;
use Filament\Pages\SettingsPage;
use ReflectionNamedType;
use ReflectionProperty;

abstract class BaseSettingsPage extends SettingsPage
{
    protected static ?string $cluster = SettingsCluster::class;

    public static function canAccess(): bool
    {
        return Access::isBeheerder();
    }

    /**
     * Een leeg tekstveld komt als null binnen; een instelling die geen null toestaat (zoals een lege
     * handtekening) wordt dan een lege tekst in plaats van een TypeError.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $settings = static::getSettings();

        if (! class_exists($settings)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if ($value !== null || ! property_exists($settings, $key)) {
                continue;
            }

            $type = (new ReflectionProperty($settings, $key))->getType();

            if ($type instanceof ReflectionNamedType && ! $type->allowsNull() && $type->getName() === 'string') {
                $data[$key] = '';
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        activity('instellingen')
            ->causedBy(Access::user())
            ->withProperties(['groep' => static::$navigationLabel])
            ->log('Instellingen gewijzigd: '.static::$navigationLabel);
    }
}
