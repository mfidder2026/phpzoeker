<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Actieve promptversies
    |--------------------------------------------------------------------------
    |
    | Prompts staan in resources/prompts/{naam}.{versie}.md. Elke AI-uitkomst slaat de
    | gebruikte versie op (§0.2). Een nieuwe versie activeren = hier aanpassen.
    |
    */

    'prompts' => [
        'cv-importer' => 'v2',
        'assignment-extractor' => 'v2',
        'alert-mail-splitter' => 'v1',
        'match-assessor' => 'v2',
    ],

    /*
    |--------------------------------------------------------------------------
    | Startwaarden AI-agent (alleen voor de seeder bij de eerste installatie)
    |--------------------------------------------------------------------------
    |
    | Daarna staat alles in Instellingen → AI-agent (database, versleuteld; ADR-08).
    |
    */

    'ai_bootstrap' => [
        'provider' => env('AI_BOOTSTRAP_PROVIDER', 'ollama'),
        'base_url' => env('AI_BOOTSTRAP_BASE_URL'),
        'api_key' => env('AI_BOOTSTRAP_API_KEY'),
        'model' => env('AI_BOOTSTRAP_MODEL'),
    ],

    'admin' => [
        'email' => env('ADMIN_EMAIL', 'michel@fremen.app'),
        'name' => env('ADMIN_NAME', 'Michel'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    | Inloggen alleen met 2FA (§19.1). Alleen voor lokale ontwikkeling uit te zetten.
    */
    'require_mfa' => (bool) env('REQUIRE_MFA', true),

    'mail_test_recipient' => env('MAIL_TEST_RECIPIENT'),

    'gotenberg_url' => env('GOTENBERG_URL', 'http://localhost:3000'),

    // PDF van het CV: gotenberg (Docker/productie), word (Windows met Microsoft Word) of auto.
    'pdf_converter' => env('PDF_CONVERTER', 'auto'),

    /*
     * Bronnen automatisch ophalen volgens hun interval (planner). Standaard uit: zoeken gebeurt
     * met de knop "Nu zoeken".
     */
    'sources_auto_fetch' => (bool) env('SOURCES_AUTO_FETCH', false),

    'pdok_url' => env('PDOK_LOCATIESERVER_URL', 'https://api.pdok.nl/bzk/locatieserver/search/v3_1'),

    'user_agent' => 'PHPZoeker/1.0 (+mailto:'.env('ADMIN_EMAIL', 'michel@fremen.app').')',

];
