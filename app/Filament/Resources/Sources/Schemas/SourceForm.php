<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Domain\Sources\Enums\SourceType;
use App\Settings\IngestSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
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
            Section::make('Alerts-mailbox (IMAP)')->visible($isType(SourceType::MailboxAlert))->columns(3)->schema([
                TextInput::make('config.host')->label('IMAP-server')->placeholder('imap.gmail.com'),
                TextInput::make('config.port')->label('Poort')->numeric()->default(993),
                Select::make('config.encryption')->label('Versleuteling')->options(['ssl' => 'SSL', 'tls' => 'STARTTLS'])->default('ssl'),
                TextInput::make('config.username')->label('Gebruikersnaam'),
                TextInput::make('config.password')->label('(App-)wachtwoord')->password()->autocomplete('new-password')
                    ->helperText('Versleuteld opgeslagen. Laat leeg om het huidige wachtwoord te behouden.'),
                TextInput::make('config.folder')->label('Map')->default('INBOX'),
                TagsInput::make('config.internal_domains')->label('Eigen domeinen')->default(['fremen.app'])->columnSpanFull()
                    ->helperText('Mails van deze domeinen zijn doorgestuurde aanvragen (bron "Handmatig", §8.6).'),
            ]),
            Section::make('Gebruiksvoorwaarden (ToS-check, §8.4)')
                ->description('Verplicht voordat de bron aan kan: geen scraping achter een login, geen captcha\'s omzeilen, robots.txt respecteren (R3).')
                ->columns(2)
                ->schema([
                    DatePicker::make('tos_reviewed_at')->label('ToS gecontroleerd op')->native(false)->displayFormat('d-m-Y')->live(),
                    Textarea::make('tos_notes')->label('Samenvatting gebruiksvoorwaarden')->rows(3)->live(onBlur: true),
                    Toggle::make('enabled')->label('Bron aan')
                        ->disabled(fn (Get $get): bool => blank($get('tos_reviewed_at')) || blank($get('tos_notes')))
                        ->helperText(fn (Get $get): string => blank($get('tos_reviewed_at')) || blank($get('tos_notes'))
                            ? 'Vul eerst de ToS-check in (datum én samenvatting).'
                            : 'De scheduler haalt de bron op volgens het interval.'),
                ]),
        ]);
    }

    private static function type(Get $get): ?SourceType
    {
        $type = $get('type');

        return $type instanceof SourceType ? $type : SourceType::tryFrom((string) $type);
    }
}
