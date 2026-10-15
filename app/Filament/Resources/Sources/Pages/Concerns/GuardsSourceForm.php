<?php

namespace App\Filament\Resources\Sources\Pages\Concerns;

/**
 * Gedeeld door aanmaken en bewerken: zonder ToS-check kan een bron niet aan (FR-B2), en een
 * leeg wachtwoord- of secretveld behoudt de opgeslagen waarde.
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

        foreach (['password', 'client_secret'] as $secret) {
            if (blank($config[$secret] ?? null) && isset($existingConfig[$secret])) {
                $config[$secret] = $existingConfig[$secret];
            }
        }

        $data['config'] = [...$existingConfig, ...array_filter($config, fn (mixed $value): bool => $value !== null)];

        return $data;
    }
}
