<?php

namespace Database\Seeders;

use App\Domain\Relations\Enums\OrganizationType;
use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Startbronnen (§8.2, docs/bronnen.md). Externe bronnen staan uit tot Fremen de ToS-check
 * heeft ingevuld (FR-B2).
 */
class SourceSeeder extends Seeder
{
    public function run(): void
    {
        Source::query()->firstOrCreate(['key' => 'handmatig'], [
            'name' => 'Handmatig',
            'type' => SourceType::Manual,
            'enabled' => true,
            'poll_interval_minutes' => 60,
            'access_method' => 'Formulier "Opdracht toevoegen" en doorgestuurde mails',
            'tos_reviewed_at' => now(),
            'tos_notes' => 'Interne invoer door Fremen; geen externe bron.',
        ]);

        $dpp = Organization::query()->firstOrCreate(
            ['normalized_name' => Organization::normalizeName('de Publieke Partner')],
            ['name' => 'de Publieke Partner', 'type' => OrganizationType::Platform, 'website' => 'https://depubliekepartner.nl'],
        );

        Source::query()->firstOrCreate(['key' => 'depubliekepartner-ict'], [
            'name' => 'De Publieke Partner — ICT & automatisering',
            'type' => SourceType::Feed,
            'base_url' => 'https://depubliekepartner.nl',
            'config' => ['feed_url' => 'https://depubliekepartner.nl/category/zzp-opdrachten-overheid/ict-en-automatisering/feed/'],
            'enabled' => false,
            'poll_interval_minutes' => 60,
            'access_method' => 'RSS-feed + openbare opdrachtpagina (robots.txt: toegestaan, gecontroleerd 15-10-2026)',
            'organization_id' => $dpp->id,
        ]);

        Source::query()->firstOrCreate(['key' => 'alerts-mailbox'], [
            'name' => 'Aanvragen-mailbox (aanvragen@fremen.app)',
            'type' => SourceType::MailboxAlert,
            'config' => [
                'protocol' => 'graph',
                'auth' => 'delegated',
                'mailbox' => 'aanvragen@fremen.app',
                'folder' => 'Inbox',
                'since_days' => 14,
                'internal_domains' => ['fremen.app'],
            ],
            'enabled' => false,
            'poll_interval_minutes' => 15,
            'access_method' => 'Gedeeld postvak in Microsoft 365 via Microsoft Graph: aanvragen van klanten en collega’s plus job alerts van platforms en intermediairs (o.a. LinkedIn)',
            'tos_notes' => 'Eigen gedeeld postvak van Fremen. Alerts komen binnen via de reguliere e-mailmeldingen van de platforms (toegestaan); de app leest alleen deze mailbox (gekoppeld via een eenmalige Microsoft-login).',
        ]);
    }
}
