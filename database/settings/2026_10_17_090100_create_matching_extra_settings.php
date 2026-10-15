<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Fase 3: aliaslijst voor technologie-overlap (§10.3), budgetvoorrang voor extractie (§18.3)
 * en de tijden voor directe meldingen en herinneringen (§14).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('matching.technology_aliases', [
            'postgres' => 'postgresql',
            'psql' => 'postgresql',
            'js' => 'javascript',
            'ts' => 'typescript',
            'nextjs' => 'next.js',
            'next' => 'next.js',
            'node' => 'node.js',
            'nodejs' => 'node.js',
            'nestjs' => 'nest.js',
            'reactjs' => 'react',
            'react.js' => 'react',
            'vuejs' => 'vue',
            'vue.js' => 'vue',
            'k8s' => 'kubernetes',
            'mariadb' => 'mysql',
            'ms sql' => 'sql server',
            'mssql' => 'sql server',
            'php 8' => 'php',
            'php 8.x' => 'php',
            'php 8.4' => 'php',
            'rest-api' => 'rest',
            "rest-api's" => 'rest',
            'rest api' => 'rest',
            'restful' => 'rest',
            'rest/json' => 'rest',
            'soap/xml' => 'soap',
            'oauth 2.0' => 'oauth',
            'oauth2' => 'oauth',
            'openid connect' => 'oidc',
            'microsoft entra id' => 'entra id',
            'azure ad' => 'entra id',
            'azure active directory' => 'entra id',
            'git flow' => 'git',
            'github' => 'git',
            'gitlab' => 'git',
            'ci/cd' => 'ci/cd',
            'phpunit' => 'phpunit',
        ]);
        $this->migrator->add('matching.extraction_budget_reserve_percent', 10);
        $this->migrator->add('matching.max_assessments_per_run', 200);
        $this->migrator->add('notifications.direct_mail', true);
        $this->migrator->add('notifications.urgent_deadline_hours', 48);
        $this->migrator->add('notifications.reminder_deadline_hours', 24);
    }
};
