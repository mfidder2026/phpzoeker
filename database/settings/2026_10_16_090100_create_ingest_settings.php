<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('ingest.default_poll_minutes', 60);
        $this->migrator->add('ingest.min_poll_minutes', 15);
        $this->migrator->add('ingest.max_items_per_run', 50);
        $this->migrator->add('ingest.relevance_keywords', [
            'developer', 'ontwikkelaar', 'programmeur', 'software', 'engineer', 'php', 'laravel', 'python', 'backend',
            'back-end', 'frontend', 'front-end', 'full stack', 'fullstack', 'full-stack', 'devops', 'api', 'integratie',
            'web', 'applicatie', 'scrum', 'sql', 'java', 'javascript', 'typescript', 'react', '.net', 'c#',
        ]);
        $this->migrator->add('ingest.full_text_min_chars', 600);
        $this->migrator->add('ingest.dedupe_title_similarity', 0.85);
        $this->migrator->add('ingest.dedupe_start_window_days', 14);
        $this->migrator->add('ingest.close_after_unseen_days', 14);
        $this->migrator->add('ingest.health_empty_hours', 48);
        $this->migrator->add('ingest.health_max_failures', 3);
    }
};
