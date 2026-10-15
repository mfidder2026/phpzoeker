<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Fase 4: waarden voor aanbiedingen (§11.4, §11.3, §13.4) en het register.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('register.similar_offer_days', 90);
        $this->migrator->add('register.similar_offer_title_similarity', 0.7);
        $this->migrator->add('register.consent_link_hours', 72);
        $this->migrator->add('mail.max_attachments_mb', 10);
        $this->migrator->add('mail.attach_docx', false);
    }
};
