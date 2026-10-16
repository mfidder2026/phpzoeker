<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Tenant van Fremen voor "Inloggen met Microsoft"; wordt gezet bij de eerste inlog van een beheerder.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.microsoft_tenant_id', null);
    }
};
