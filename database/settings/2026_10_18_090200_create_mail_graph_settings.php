<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Verbinding met Microsoft 365 voor het versturen van aanbiedingen (§13.2). Het
 * vernieuwingstoken staat versleuteld opgeslagen.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->addEncrypted('mail.graph_refresh_token', null);
        $this->migrator->add('mail.graph_tenant', null);
        $this->migrator->add('mail.graph_account', null);
        $this->migrator->add('mail.graph_connected_at', null);
    }
};
