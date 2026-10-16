<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Mailbox\GraphMailboxClient;
use App\Domain\Sources\Models\Source;
use App\Settings\IngestSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SourceForm
{
    public static function configure(Schema $schema): Schema
    {
        $settings = app(IngestSettings::class);
        $isType = fn (SourceType $type): \Closure => fn (Get $get): bool => self::type($get) === $type;

        return $schema->components([
            Section::make('Bron')->columns(2)->schema([
                TextInput::make('name')->label('Naam')->required()->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $operation, ?string $state, Set $set) => $operation === 'create' ? $set('key', Str::slug((string) $state)) : null),
                TextInput::make('key')->label('Sleutel')->required()->unique(ignoreRecord: true)->alphaDash(),
                Select::make('type')->label('Type')->options(SourceType::class)->required()->live(),
                TextInput::make('base_url')->label('Website')->url()
                    ->helperText('Domein van de bron; alleen hiervan haalt de app volledige opdrachtpagina\'s op.'),
                Select::make('organization_id')->label('Intermediair / platform')->relationship('organization', 'name')->searchable()->preload()
                    ->createOptionForm([TextInput::make('name')->label('Naam')->required()]),
                TextInput::make('access_method')->label('Toegangsmethode')->placeholder('Bijv. RSS-feed, job alerts naar de mailbox'),
                TextInput::make('poll_interval_minutes')->label('Interval')->numeric()->suffix('min')
                    ->minValue($settings->min_poll_minutes)->default($settings->default_poll_minutes)->required(),
                TextInput::make('default_response_term_days')->label('Standaard reactietermijn')->numeric()->suffix('werkdagen')->nullable()
                    ->helperText('Leeg = de standaard van de opdrachtgever of de globale standaard (§11.6).'),
            ]),
            Section::make('Feed')->visible($isType(SourceType::Feed))->schema([
                TextInput::make('config.feed_url')->label('Feed-URL (RSS of Atom)')->url()->required(fn (Get $get): bool => self::type($get) === SourceType::Feed),
            ]),
            Section::make('Overzichtspagina')->visible($isType(SourceType::Html))
                ->description('Openbare pagina met opdrachten, zonder inloggen (R3). De app volgt robots.txt en haalt daarna per opdracht de openbare pagina op.')
                ->schema([
                    TagsInput::make('config.listing_urls')->label('Overzichtspagina\'s')->nestedRecursiveRules(['url'])
                        ->required(fn (Get $get): bool => self::type($get) === SourceType::Html)
                        ->helperText('Bij voorkeur gefilterd op ICT en gesorteerd op nieuwste.'),
                    TextInput::make('config.link_pattern')->label('Linkpatroon (regex op het pad)')->placeholder('#^/vacatures/[a-z0-9-]+/?$#')
                        ->required(fn (Get $get): bool => self::type($get) === SourceType::Html),
                    TagsInput::make('config.exclude_patterns')->label('Uitsluiten (regex)')->placeholder('#/feed/?$#'),
                    TextInput::make('config.max_items')->label('Maximaal aantal per run')->numeric()->minValue(1)->maxValue(200)->default(40),
                ]),
            Section::make('Mailbox')->visible($isType(SourceType::MailboxAlert))
                ->description('De mailbox waar aanvragen en job alerts binnenkomen. Elke nieuwe mail wordt een opdracht; er wordt niets verwijderd.')
                ->columns(3)
                ->schema([
                    Select::make('config.protocol')->label('Koppeling')->required()->live()->default('graph')
                        ->options(['graph' => 'Microsoft 365 (Microsoft Graph)', 'imap' => 'IMAP (bijv. Gmail)']),
                    TextInput::make('config.mailbox')->label('Mailbox')->email()->placeholder('aanvragen@fremen.app')
                        ->visible(fn (Get $get): bool => $get('config.protocol') === 'graph')->required(fn (Get $get): bool => $get('config.protocol') === 'graph'),
                    TextInput::make('config.folder')->label('Map')->default('Inbox'),
                    Select::make('config.auth')->label('Aanmelden')->default('delegated')->live()
                        ->options(['delegated' => 'Eenmalig inloggen met een Microsoft-account (aanbevolen)', 'app' => 'Eigen app-registratie (geavanceerd)'])
                        ->visible(fn (Get $get): bool => $get('config.protocol') === 'graph'),
                    Text::make(fn (?Source $record): string => self::connectionStatus($record))->columnSpan(2)
                        ->visible(fn (Get $get): bool => $get('config.protocol') === 'graph' && ($get('config.auth') ?? 'delegated') !== 'app'),
                    TextInput::make('config.tenant_id')->label('Tenant-ID')->visible(fn (Get $get): bool => $get('config.protocol') === 'graph' && $get('config.auth') === 'app'),
                    TextInput::make('config.client_id')->label('Client-ID (app-registratie)')->visible(fn (Get $get): bool => $get('config.protocol') === 'graph' && $get('config.auth') === 'app'),
                    TextInput::make('config.client_secret')->label('Client-secret')->password()->autocomplete('new-password')
                        ->visible(fn (Get $get): bool => $get('config.protocol') === 'graph' && $get('config.auth') === 'app')
                        ->helperText('Versleuteld opgeslagen en nooit meer getoond. Laat leeg om het huidige secret te behouden.'),
                    TextInput::make('config.processed_category')->label('Categorie voor verwerkte mails')->placeholder(GraphMailboxClient::DEFAULT_CATEGORY)
                        ->visible(fn (Get $get): bool => $get('config.protocol') === 'graph')
                        ->helperText('Gelezen/ongelezen blijft van de collega\'s; de app herkent verwerkte mails aan deze categorie.'),
                    TextInput::make('config.since_days')->label('Eerste keer terugkijken')->numeric()->minValue(1)->maxValue(90)->suffix('dagen')->default(14)
                        ->visible(fn (Get $get): bool => $get('config.protocol') === 'graph'),
                    TextInput::make('config.host')->label('IMAP-server')->placeholder('imap.gmail.com')->visible(fn (Get $get): bool => $get('config.protocol') === 'imap'),
                    TextInput::make('config.port')->label('Poort')->numeric()->default(993)->visible(fn (Get $get): bool => $get('config.protocol') === 'imap'),
                    Select::make('config.encryption')->label('Versleuteling')->options(['ssl' => 'SSL', 'tls' => 'STARTTLS'])->default('ssl')->visible(fn (Get $get): bool => $get('config.protocol') === 'imap'),
                    TextInput::make('config.username')->label('Gebruikersnaam')->visible(fn (Get $get): bool => $get('config.protocol') === 'imap'),
                    TextInput::make('config.password')->label('(App-)wachtwoord')->password()->autocomplete('new-password')
                        ->visible(fn (Get $get): bool => $get('config.protocol') === 'imap')
                        ->helperText('Versleuteld opgeslagen. Laat leeg om het huidige wachtwoord te behouden.'),
                    TagsInput::make('config.internal_domains')->label('Eigen domeinen')->default(['fremen.app'])->columnSpanFull()
                        ->helperText('Mails van deze domeinen zijn doorgestuurde aanvragen van collega\'s (§8.6); andere mails zijn alerts van platforms en intermediairs.'),
                ]),
            Section::make('API (JSON)')->visible(fn (Get $get): bool => in_array(self::type($get), [SourceType::Api, SourceType::TenderNed], true))
                ->description('Openbare JSON-API. Geef aan waar de lijst met publicaties staat en welke velden de titel, link, tekst en datum zijn (punt-paden, bijv. link.href).')
                ->columns(3)
                ->schema([
                    TextInput::make('config.api_url')->label('API-URL')->url()->columnSpanFull()
                        ->required(fn (Get $get): bool => (bool) $get('enabled')),
                    TextInput::make('config.items_path')->label('Pad naar de lijst')->placeholder('Leeg = hele respons, bijv. content of jobs'),
                    TextInput::make('config.fields.id')->label('Veld: ID')->placeholder('id'),
                    TextInput::make('config.fields.title')->label('Veld: titel')->placeholder('title'),
                    TextInput::make('config.fields.url')->label('Veld: link')->placeholder('url'),
                    TextInput::make('config.url_template')->label('Link-sjabloon')->placeholder('https://…/{id}')
                        ->helperText('Alleen als de API geen link geeft.'),
                    TextInput::make('config.fields.date')->label('Veld: datum')->placeholder('date'),
                    TagsInput::make('config.fields.body')->label('Velden voor de tekst')->placeholder('description of Opdrachtgever=opdrachtgeverNaam')->columnSpan(2),
                    TextInput::make('config.max_items')->label('Maximaal aantal per run')->numeric()->minValue(1)->maxValue(200)->default(50),
                ]),
            Section::make('Job alert')->visible($isType(SourceType::Alert))
                ->description('De app haalt deze bron niet zelf op. Maak bij het platform een job alert (of e-mailnotificatie) aan naar aanvragen@fremen.app; de aanvragen-mailbox verwerkt die mails. Zet de bron daarna aan als geheugensteun dat de alert loopt.')
                ->columns(2)
                ->schema([
                    TextInput::make('config.alert_url')->label('Waar stel je de alert in')->url()->placeholder('https://…'),
                    TextInput::make('config.alert_address')->label('Alert gaat naar')->email()->default('aanvragen@fremen.app'),
                    TagsInput::make('config.sender_domains')->label('Afzenderdomeinen van de alerts')->placeholder('freelance.nl'),
                    Textarea::make('config.alert_tips')->label('Zoekopdracht / tips')->rows(2)->placeholder('Bijv. PHP, Laravel, Symfony; regio Nederland'),
                ]),
            Section::make('Gebruiksvoorwaarden (ToS-check, §8.4)')
                ->description('Verplicht voordat de bron aan kan: geen scraping achter een login, geen captcha\'s omzeilen, robots.txt respecteren (R3).')
                ->columns(2)
                ->schema([
                    DatePicker::make('tos_reviewed_at')->label('ToS gecontroleerd op')->native(false)->displayFormat('d-m-Y')->live(),
                    Textarea::make('tos_notes')->label('Samenvatting gebruiksvoorwaarden')->rows(3)->live(onBlur: true),
                    Toggle::make('enabled')->label(fn (Get $get): string => self::type($get) === SourceType::Alert ? 'Alert is ingesteld' : 'Bron aan')
                        ->disabled(fn (Get $get): bool => self::type($get) !== SourceType::Alert && (blank($get('tos_reviewed_at')) || blank($get('tos_notes'))))
                        ->helperText(fn (Get $get): string => match (true) {
                            self::type($get) === SourceType::Alert => 'Een alert ontvangt alleen de gewone e-mailmeldingen van het platform; daar is geen ToS-check voor nodig.',
                            blank($get('tos_reviewed_at')) || blank($get('tos_notes')) => 'Vul eerst de ToS-check in (datum én samenvatting).',
                            default => 'De scheduler haalt de bron op volgens het interval.',
                        }),
                ]),
        ]);
    }

    private static function connectionStatus(?Source $source): string
    {
        $config = $source->config ?? [];

        if (blank($config['refresh_token'] ?? null)) {
            return $source === null
                ? 'Sla de bron eerst op; daarna kun je rechtsboven inloggen bij Microsoft.'
                : 'Nog niet verbonden. Klik rechtsboven op "Inloggen bij Microsoft" en log één keer in met een account dat toegang heeft tot deze mailbox.';
        }

        $since = isset($config['connected_at']) ? Carbon::parse((string) $config['connected_at'])->format('d-m-Y H:i') : '';

        return '✓ Verbonden met Microsoft 365 als '.($config['connected_account'] ?? 'onbekend account').($since !== '' ? ' sinds '.$since : '').'. De app vernieuwt de verbinding zelf.';
    }

    private static function type(Get $get): ?SourceType
    {
        $type = $get('type');

        return $type instanceof SourceType ? $type : SourceType::tryFrom((string) $type);
    }
}
