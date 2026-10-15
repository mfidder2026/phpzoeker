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

        $overheidZzp = Organization::query()->firstOrCreate(
            ['normalized_name' => Organization::normalizeName('OverheidZZP')],
            ['name' => 'OverheidZZP', 'type' => OrganizationType::Platform, 'website' => 'https://overheidzzp.nl'],
        );

        Source::query()->firstOrCreate(['key' => 'overheidzzp-ict'], [
            'name' => 'OverheidZZP — ICT & informatievoorziening',
            'type' => SourceType::Html,
            'base_url' => 'https://overheidzzp.nl',
            'config' => [
                'listing_urls' => [
                    'https://overheidzzp.nl/vacatures/?vacancy_tag%5B%5D=ict-informatievoorziening&sort=newest',
                    'https://overheidzzp.nl/vacatures/?search=developer&sort=newest',
                ],
                'link_pattern' => '#^/vacatures/[a-z0-9-]+/?$#',
                'exclude_patterns' => ['#/feed/?$#'],
                'max_items' => 40,
            ],
            'enabled' => false,
            'poll_interval_minutes' => 60,
            'access_method' => 'Openbare overzichtspagina (filter ICT, nieuwste eerst) + openbare opdrachtpagina; geen feed beschikbaar (gecontroleerd 15-10-2026)',
            'tos_notes' => 'robots.txt (15-10-2026): /vacatures/ en de zoekpagina toegestaan; feeds (*/feed/), /page/ en /search/ uitgesloten, die gebruikt de app niet. Disclaimer: geen bepaling over geautomatiseerde toegang; de pagina Algemene voorwaarden is leeg. Opdrachten zijn openbaar zonder inloggen. Besluit door Fremen.',
            'organization_id' => $overheidZzp->id,
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
