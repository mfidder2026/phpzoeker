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

        $this->seedCatalogFeeds();
        $this->seedCatalogAlerts();
        $this->seedCatalogApis();
        $this->seedResearchedSources();
    }

    /**
     * Extra bronnen uit het bronnenonderzoek van 16-10-2026: openbare feeds, API's en
     * overzichtspagina's waarvan robots.txt het ophalen toestaat. Staan uit tot Fremen de
     * ToS-check heeft bevestigd (Bronnen → selecteren → "ToS-check akkoord en aanzetten").
     */
    private function seedResearchedSources(): void
    {
        $checked = 'robots.txt gecontroleerd op 16-10-2026';

        $sources = [
            // Feeds
            ['devitjobs-nl', 'DevITjobs.nl', SourceType::Feed, 'https://devitjobs.nl', ['feed_url' => 'https://devitjobs.nl/job_feed.xml'],
                'Vacature-XML (Indeed-formaat)', "{$checked}: toegestaan (alleen /api/ uitgesloten voor één bot). 233 IT-vacatures in NL, veel PHP/Laravel; vooral vast dienstverband."],
            ['larajobs', 'LaraJobs', SourceType::Feed, 'https://larajobs.com', ['feed_url' => 'https://larajobs.com/feed'],
                'RSS-feed', "{$checked}: niets uitgesloten. Laravel-vacatures, remote en Europa."],
            ['wwr-fullstack', 'We Work Remotely — full-stack', SourceType::Feed, 'https://weworkremotely.com', ['feed_url' => 'https://weworkremotely.com/categories/remote-full-stack-programming-jobs.rss'],
                'RSS-feed', "{$checked}: toegestaan. Remote full-stack, wereldwijd (filter op NL/EU gebeurt bij het matchen)."],
            ['wwr-backend', 'We Work Remotely — back-end', SourceType::Feed, 'https://weworkremotely.com', ['feed_url' => 'https://weworkremotely.com/categories/remote-back-end-programming-jobs.rss'],
                'RSS-feed', "{$checked}: toegestaan. Remote back-end, wereldwijd."],
            ['jobspresso', 'Jobspresso', SourceType::Feed, 'https://jobspresso.co', ['feed_url' => 'https://jobspresso.co/jobs/feed/'],
                'RSS-feed', "{$checked}: toegestaan (niet de variant met ?feed=, die is uitgesloten). Remote, gemengd."],

            // API's
            ['ngage-it', 'Ngage IT', SourceType::Api, 'https://www.ngage-it.nl', [
                'api_url' => 'https://www.ngage-it.nl/wp-json/wp/v2/vacatures?per_page=50',
                'items_path' => '', 'fields' => ['id' => 'id', 'title' => 'title.rendered', 'url' => 'link', 'date' => 'date', 'body' => ['content.rendered', 'excerpt.rendered']],
            ], 'Openbare WordPress-API (vacatures)', "{$checked}: toegestaan (alleen zoekpagina's uitgesloten). IT-recruiter, 308 vacatures, veel developers."],
            ['jobicy-nl', 'Jobicy — remote vanuit NL', SourceType::Api, 'https://jobicy.com', [
                'api_url' => 'https://jobicy.com/api/v2/remote-jobs?count=50&geo=netherlands',
                'items_path' => 'jobs', 'fields' => ['id' => 'id', 'title' => 'jobTitle', 'url' => 'url', 'date' => 'pubDate', 'body' => ['Bedrijf=companyName', 'Locatie=jobGeo', 'Type=jobType', 'jobDescription']],
            ], 'Openbare API', "{$checked}: toegestaan (alleen api_access_key= uitgesloten). Remote banen die vanuit NL kunnen."],
            ['himalayas-nl', 'Himalayas — developers NL', SourceType::Api, 'https://himalayas.app', [
                'api_url' => 'https://himalayas.app/jobs/api/search?country=NL&q=developer',
                'items_path' => 'jobs', 'fields' => ['id' => 'guid', 'title' => 'title', 'url' => 'applicationLink', 'date' => 'pubDate', 'body' => ['Bedrijf=companyName', 'Type=employmentType', 'description']],
            ], 'Openbare API', "{$checked}: toegestaan (alleen page=-paden uitgesloten). Remote developer-banen open voor NL, deels freelance (Contractor)."],

            // Openbare overzichtspagina's
            ['opdrachtoverheid', 'Opdracht Overheid — ICT', SourceType::Html, 'https://www.opdrachtoverheid.nl', [
                'listing_urls' => [
                    'https://www.opdrachtoverheid.nl/vakgebieden/fullstack-developer',
                    'https://www.opdrachtoverheid.nl/vakgebieden/applicatieontwikkelaar',
                    'https://www.opdrachtoverheid.nl/vakgebieden/software-engineer',
                    'https://www.opdrachtoverheid.nl/vakgebieden/projectleider-ict',
                ],
                'link_pattern' => '#^/inhuuropdracht/[^/]+/[^/]+/[0-9A-Fa-f-]{36}/?$#',
                'max_items' => 40,
            ], 'Openbare overzichtspagina per vakgebied + openbare opdrachtpagina', "{$checked}: toegestaan; /*?vakgebied=* is uitgesloten, daarom de padvorm. Freelance inhuur bij de overheid."],
            ['freelancermap-nl', 'freelancermap — projecten NL', SourceType::Html, 'https://www.freelancermap.com', [
                'listing_urls' => ['https://www.freelancermap.com/projects/netherlands'],
                'link_pattern' => '#^/project/[a-z0-9-]+#',
                'max_items' => 40,
            ], 'Openbare overzichtspagina + openbare projectpagina', "{$checked}: niets uitgesloten. Freelance ICT-projecten in NL; contactgegevens staan achter een login en worden niet opgehaald."],
            ['computable-vacatures', 'Computable — vacatures', SourceType::Html, 'https://www.computable.nl', [
                'listing_urls' => ['https://www.computable.nl/vacatures/'],
                'link_pattern' => '#^/vacature/[a-z0-9-]+/[0-9a-f-]{36}#',
                'max_items' => 30,
            ], 'Openbare overzichtspagina + openbare vacaturepagina', "{$checked}: toegestaan voor alle bots (AI-bots alleen uitgesloten van /artikelen/). IT-vacatures in NL."],
            ['werkenvoornederland-ict', 'Werken voor Nederland — ICT', SourceType::Html, 'https://www.werkenvoornederland.nl', [
                'listing_urls' => ['https://www.werkenvoornederland.nl/vacatures?_hn:type=component-rendering&_hn:ref=r46_r1_r4&vakgebied=CVG.08'],
                'link_pattern' => '#^/vacatures/[a-z0-9-]+-[A-Z0-9]+-\d{4}-\d+#',
                'max_items' => 30,
            ], 'Openbare overzicht (ICT) + openbare vacaturepagina', "{$checked}: alleen /login uitgesloten. ICT-vacatures bij de Rijksoverheid. Let op: de overzichts-URL kan veranderen als de site wordt vernieuwd."],
            ['michaelpage-it', 'Michael Page — IT', SourceType::Html, 'https://www.michaelpage.nl', [
                'listing_urls' => ['https://www.michaelpage.nl/jobs/information-technology'],
                'link_pattern' => '#^/job-detail/[a-z0-9-]+/ref/jn-[0-9-]+#',
                'max_items' => 30,
            ], 'Openbare overzichtspagina + openbare vacaturepagina', "{$checked}: toegestaan; /search/ en /job-apply/ uitgesloten, die gebruikt de app niet. IT, deels finance/BI."],
            ['randstad-ict', 'Randstad — ICT', SourceType::Html, 'https://www.randstad.nl', [
                'listing_urls' => ['https://www.randstad.nl/vacatures/ict'],
                'link_pattern' => '#^/vacatures/\d+/[a-z0-9-]+#',
                'max_items' => 30,
            ], 'Openbare overzichtspagina + openbare vacaturepagina', "{$checked}: toegestaan. ICT-vacatures (vast en uitzend, veel servicedesk)."],
        ];

        foreach ($sources as [$key, $name, $type, $baseUrl, $config, $method, $notes]) {
            Source::query()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'type' => $type,
                'base_url' => $baseUrl,
                'config' => $config,
                'enabled' => false,
                'poll_interval_minutes' => 60,
                'access_method' => $method,
                'tos_notes' => $notes.' Besluit door Fremen.',
                'organization_id' => $this->organization(
                    explode(' — ', $name)[0],
                    in_array($key, ['ngage-it', 'michaelpage-it', 'randstad-ict'], true) ? OrganizationType::Intermediair : OrganizationType::Platform,
                    $baseUrl,
                )->id,
            ]);
        }
    }

    /**
     * Openbare opdrachtfeeds uit de bronnencatalogus (§8.2), gecontroleerd op 15-10-2026.
     * Staan uit tot Fremen de ToS-check heeft afgerond.
     */
    private function seedCatalogFeeds(): void
    {
        $feeds = [
            'quest4-opdrachten' => ['Quest4 — opdrachten', 'Quest4', 'https://quest4.nl', 'https://quest4.nl/opdracht/feed/',
                'robots.txt (15-10-2026): alles toegestaan behalve /wp-admin/ en /wp-includes/. Feed /opdracht/feed/ met 12 actuele opdrachten (overheid, ICT). De feed /vacature/feed/ is sinds 2025 niet bijgewerkt en wordt niet gebruikt. Besluit door Fremen.'],
            'morganblack-jobs' => ['Morgan Black — jobs', 'Morgan Black', 'https://www.morganblack.nl', 'https://www.morganblack.nl/jobs/feed/',
                'robots.txt (15-10-2026): Allow: / voor alle bots; Content-Signal search=yes, ai-input=yes, ai-train=no (de app traint niet). Feed /jobs/feed/ met 10 actuele vacatures en opdrachten (mix van detachering en vast). Besluit door Fremen.'],
            'b-hunted-opdrachten' => ['B-Hunted — opdrachten', 'B-Hunted', 'https://www.b-hunted.nl', 'https://www.b-hunted.nl/feed/',
                'robots.txt (15-10-2026): niets uitgesloten. De hoofdfeed bevat de opdrachten (10 items, alle vakgebieden; de voorfilter houdt alleen ICT over). Besluit door Fremen.'],
        ];

        foreach ($feeds as $key => [$name, $organizationName, $baseUrl, $feedUrl, $notes]) {
            Source::query()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'type' => SourceType::Feed,
                'base_url' => $baseUrl,
                'config' => ['feed_url' => $feedUrl],
                'enabled' => false,
                'poll_interval_minutes' => 60,
                'access_method' => 'RSS-feed + openbare opdrachtpagina',
                'tos_notes' => $notes,
                'organization_id' => $this->organization($organizationName, OrganizationType::Intermediair, $baseUrl)->id,
            ]);
        }
    }

    /**
     * Bronnen uit de catalogus (§8.2) die via job alerts in de aanvragen-mailbox binnenkomen.
     * Geen scraping: veel van deze sites vragen een login of sluiten geautomatiseerde toegang uit (R3).
     */
    private function seedCatalogAlerts(): void
    {
        $platform = OrganizationType::Platform;
        $intermediair = OrganizationType::Intermediair;

        /** @var list<array{0: string, 1: string, 2: OrganizationType, 3: string, 4: string, 5: int, 6: string}> $alerts */
        $alerts = [
            ['striive', 'Striive (HeadFirst)', $platform, 'https://striive.com', 'striive.com', 1, 'Leveranciersaccount van Fremen nodig; zet daarin e-mailnotificaties aan voor ICT-opdrachten.'],
            ['freelance-nl', 'Freelance.nl', $platform, 'https://www.freelance.nl', 'freelance.nl', 1, 'Opdrachten-alert per zoekopdracht. robots.txt sluit AI-bots en onbekende crawlers uit, dus alleen alerts.'],
            ['hoofdkraan', 'Hoofdkraan.nl', $platform, 'https://www.hoofdkraan.nl', 'hoofdkraan.nl', 1, 'Geen openbare opdrachtfeed gevonden (15-10-2026); e-mailalert instellen.'],
            ['it-contracts', 'IT-contracts.nl', $platform, 'https://www.it-contracts.nl', 'it-contracts.nl', 1, 'Geen bruikbare feed gevonden (15-10-2026); e-mailalert instellen.'],
            ['freelancer-nl', 'Freelancer.nl', $platform, 'https://www.freelancer.nl', 'freelancer.nl', 1, 'Opdrachten-alert per zoekopdracht.'],
            ['linkedin-jobs', 'LinkedIn Jobs', $platform, 'https://www.linkedin.com/jobs', 'linkedin.com', 1, 'Alleen job-alert-mails (R3): LinkedIn verbiedt scraping en geautomatiseerde toegang. Stel per zoekopdracht een job alert in met e-mail naar de aanvragen-mailbox.'],
            ['indeed-nl', 'Indeed NL', $platform, 'https://nl.indeed.com', 'indeed.com', 1, 'Job-alert-mails per zoekopdracht (bijv. "PHP developer freelance").'],
            ['bijoranje', 'Bij Oranje', $platform, 'https://www.bijoranje.nl', 'bijoranje.nl', 2, 'Opdrachten voor de overheid; alert instellen.'],
            ['tenmonks', 'TenMonks', $intermediair, 'https://tenmonks.nl', 'tenmonks.nl', 2, 'De feed bevat geen opdrachten (15-10-2026); alert instellen.'],
            ['bluetrail', 'BlueTrail', $intermediair, 'https://www.bluetrail.nl', 'bluetrail.nl', 2, 'Overheidsopdrachten; alert instellen.'],
            ['einvigi', 'Einvigi', $intermediair, 'https://www.einvigi.nl', 'einvigi.nl', 2, 'Alert instellen.'],
            ['tentalent', 'TenTalent', $intermediair, 'https://www.tentalent.nl', 'tentalent.nl', 2, 'Website blokkeert geautomatiseerde verzoeken; alleen alerts.'],
            ['headfirst', 'HeadFirst', $intermediair, 'https://www.headfirst.nl', 'headfirst.nl', 2, 'Opdrachten lopen via Striive; alert of notificaties instellen.'],
            ['yacht', 'Yacht', $intermediair, 'https://www.yacht.nl', 'yacht.nl', 2, 'Job alert instellen.'],
            ['brainnet', 'Brainnet', $intermediair, 'https://www.brainnet.nl', 'brainnet.nl', 2, 'Alert instellen.'],
            ['huxley', 'Huxley', $intermediair, 'https://www.huxley.com', 'huxley.com', 2, 'Website blokkeert geautomatiseerde verzoeken; job alert instellen.'],
            ['computer-futures', 'Computer Futures', $intermediair, 'https://www.computerfutures.com', 'computerfutures.com', 2, 'Website blokkeert geautomatiseerde verzoeken; job alert instellen.'],
            ['hays', 'Hays', $intermediair, 'https://www.hays.nl', 'hays.nl', 2, 'Job alert instellen (IT, freelance).'],
            ['harvey-nash', 'Harvey Nash', $intermediair, 'https://www.harveynash.nl', 'harveynash.nl', 2, 'Job alert instellen.'],
            ['circle8', 'Circle8', $intermediair, 'https://www.circle8.nl', 'circle8.nl', 2, 'Website weigert geautomatiseerde verzoeken (429); alert instellen.'],
            ['waymakers', 'Waymakers', $intermediair, 'https://www.waymakers.nl', 'waymakers.nl', 2, 'Alert instellen.'],
            ['flextender', 'Flextender', $platform, 'https://www.flextender.nl', 'flextender.nl', 2, 'Inhuurplatform overheid; de feed bevat alleen nieuws. Leveranciersaccount en notificaties instellen.'],
            ['ctm-mercell', 'CTM / Mercell', $platform, 'https://www.mercell.com', 'mercell.com', 2, 'Inhuur- en aanbestedingsplatform; leveranciersaccount en notificaties instellen.'],
            ['das', 'DAS (Dynamisch Aankoopsysteem)', $platform, 'https://www.tenderned.nl', 'tenderned.nl', 2, 'Opdrachten binnen een DAS komen via het platform van de aanbesteder; notificaties instellen.'],
            ['pooq', 'Pooq', $platform, 'https://www.pooq.nl', 'pooq.nl', 3, 'Alert instellen.'],
            ['funle', 'Funle', $platform, 'https://www.funle.nl', 'funle.nl', 3, 'Alert instellen.'],
            ['freelapp', 'Freelapp', $platform, 'https://www.freelapp.nl', 'freelapp.nl', 3, 'Alert instellen.'],
            ['4-freelancers', '4-freelancers', $platform, 'https://www.4-freelancers.nl', '4-freelancers.nl', 3, 'Alert instellen.'],
            ['zzp-opdrachten', 'ZZP-Opdrachten', $platform, 'https://www.zzp-opdrachten.nl', 'zzp-opdrachten.nl', 3, 'De feed bevat geen opdrachten (15-10-2026); alert instellen.'],
            ['younited', 'Younited', $platform, 'https://www.younited.nl', 'younited.nl', 3, 'Alert instellen.'],
            ['jellow', 'Jellow', $platform, 'https://www.jellow.com', 'jellow.com', 3, 'Website weigert geautomatiseerde verzoeken; alleen alerts.'],
            ['malt', 'Malt', $platform, 'https://www.malt.nl', 'malt.nl', 3, 'Opdrachten komen via een profiel op Malt; notificaties instellen.'],
            ['lajos', 'Lajos.nl', $platform, 'https://www.lajos.nl', 'lajos.nl', 3, 'Geen bruikbare feed gevonden (15-10-2026); alert instellen.'],
        ];

        foreach ($alerts as [$key, $name, $organizationType, $website, $senderDomain, $priority, $tip]) {
            Source::query()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'type' => SourceType::Alert,
                'base_url' => $website,
                'config' => [
                    'alert_url' => $website,
                    'alert_address' => 'aanvragen@fremen.app',
                    'sender_domains' => [$senderDomain],
                    'alert_tips' => $tip,
                    'priority' => $priority,
                ],
                'enabled' => false,
                'poll_interval_minutes' => 60,
                'access_method' => 'Job alerts → aanvragen-mailbox (prio '.$priority.')',
                'tos_notes' => 'Alleen de gewone e-mailmeldingen van het platform; de app bezoekt de site niet zelf.',
                'organization_id' => $this->organization($name, $organizationType, $website)->id,
            ]);
        }
    }

    /**
     * API-bronnen uit de catalogus (§8.2). Hiervoor is nog geen connector gebouwd.
     */
    private function seedCatalogApis(): void
    {
        $tenderNed = [
            'api_url' => 'https://www.tenderned.nl/papi/tenderned-rs-tns/v2/publicaties?page=0&size=50&cpvCodes=72000000-5',
            'items_path' => 'content',
            'fields' => [
                'id' => 'publicatieId', 'title' => 'aanbestedingNaam', 'url' => 'link.href', 'date' => 'publicatieDatum',
                'body' => ['Opdrachtgever=opdrachtgeverNaam', 'Omschrijving=opdrachtBeschrijving', 'Soort publicatie=typePublicatie.omschrijving', 'Procedure=procedure.omschrijving', 'Sluitingsdatum=sluitingsDatum'],
            ],
            'max_items' => 50,
        ];

        $source = Source::query()->firstOrCreate(['key' => 'tenderned-ict'], [
            'name' => 'TenderNed — ICT-aanbestedingen',
            'type' => SourceType::TenderNed,
            'base_url' => 'https://www.tenderned.nl',
            'config' => $tenderNed,
            'enabled' => false,
            'poll_interval_minutes' => 240,
            'access_method' => 'Open data-API van TenderNed (TNS), alleen CPV 72000000 (IT-diensten)',
            'tos_notes' => 'Open data van de overheid; geen login of key nodig. robots.txt gecontroleerd op 16-10-2026: alleen /cms/ uitgesloten.',
        ]);

        // Bestaande installaties hadden nog geen API-configuratie voor TenderNed.
        if (blank($source->configValue('api_url'))) {
            $source->forceFill(['config' => $tenderNed, 'access_method' => 'Open data-API van TenderNed (TNS), alleen CPV 72000000 (IT-diensten)'])->save();
        }

        Source::query()->firstOrCreate(['key' => 'adzuna'], [
            'name' => 'Adzuna (vacature-API)',
            'type' => SourceType::Api,
            'base_url' => 'https://www.adzuna.nl',
            'enabled' => false,
            'poll_interval_minutes' => 240,
            'access_method' => 'Officiële API van Adzuna (API-key nodig, connector nog te bouwen)',
        ]);
    }

    private function organization(string $name, OrganizationType $type, string $website): Organization
    {
        return Organization::query()->firstOrCreate(
            ['normalized_name' => Organization::normalizeName($name)],
            ['name' => $name, 'type' => $type, 'website' => $website],
        );
    }
}
