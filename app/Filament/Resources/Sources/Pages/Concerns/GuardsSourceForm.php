<?php

namespace App\Filament\Resources\Sources\Pages\Concerns;

/**
 * Gedeeld door aanmaken en bewerken: zonder ToS-check kan een bron niet aan (FR-B2), en een
 * leeg wachtwoordveld behoudt het opgeslagen wachtwoord.
 */
trait GuardsSourceForm
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $existingConfig
     * @return array<string, mixed>
     */
    protected function guardSourceData(array $data, array $existingConfig = []): array
    {
        if (blank($data['tos_reviewed_at'] ?? null) || blank($data['tos_notes'] ?? null)) {
            $data['enabled'] = false;
        }

        $config = (array) ($data['config'] ?? []);

        if (blank($config['password'] ?? null) && isset($existingConfig['password'])) {
            $config['password'] = $existingConfig['password'];
        }

        $data['config'] = [...$existingConfig, ...array_filter($config, fn (mixed $value): bool => $value !== null)];

        return $data;
    }
}
