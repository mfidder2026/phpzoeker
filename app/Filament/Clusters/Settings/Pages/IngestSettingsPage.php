<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Settings\IngestSettings;
use BackedEnum;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class IngestSettingsPage extends BaseSettingsPage
{
    protected static string $settings = IngestSettings::class;

    protected static ?string $slug = 'ingest';

    protected static ?string $navigationLabel = 'Bronnen & ingest';

    protected static ?string $title = 'Bronnen & ingest';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ophalen (§8.4)')->columns(3)->schema([
                TextInput::make('default_poll_minutes')->label('Standaardinterval')->numeric()->minValue(15)->suffix('min')->required(),
                TextInput::make('min_poll_minutes')->label('Minimaal interval')->numeric()->minValue(5)->suffix('min')->required(),
                TextInput::make('max_items_per_run')->label('Max. publicaties per run')->numeric()->minValue(1)->required()
                    ->helperText('Begrenst AI-kosten per run.'),
                TextInput::make('full_text_min_chars')->label('Volledige pagina ophalen onder')->numeric()->minValue(0)->suffix('tekens')->required()
                    ->helperText('Alleen openbare pagina\'s op het domein van de bron, met respect voor robots.txt.'),
            ]),
            Section::make('Relevantie (FR-O4)')->schema([
                TagsInput::make('relevance_keywords')->label('Termen voor softwareontwikkeling')
                    ->helperText('Zonder één van deze termen in titel of tekst wordt een publicatie als "niet relevant" gemarkeerd, zonder AI-aanroep.'),
            ]),
            Section::make('Ontdubbelen en levenscyclus (§9.4, §9.5)')->columns(3)->schema([
                TextInput::make('dedupe_title_similarity')->label('Titel-similariteit')->numeric()->minValue(0.5)->maxValue(1)->step(0.01)->required(),
                TextInput::make('dedupe_start_window_days')->label('Startdatum binnen')->numeric()->minValue(0)->suffix('dagen')->required(),
                TextInput::make('close_after_unseen_days')->label('Sluiten na niet meer gezien')->numeric()->minValue(1)->suffix('dagen')->required(),
            ]),
            Section::make('Gezondheid (FR-B6)')->columns(2)->schema([
                TextInput::make('health_empty_hours')->label('Waarschuwing als niets nieuws na')->numeric()->minValue(1)->suffix('uur')->required(),
                TextInput::make('health_max_failures')->label('Waarschuwing na mislukte runs')->numeric()->minValue(1)->required(),
            ]),
        ]);
    }
}
