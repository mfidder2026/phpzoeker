<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Settings\NotificationSettings;
use BackedEnum;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class NotificationSettingsPage extends BaseSettingsPage
{
    protected static string $settings = NotificationSettings::class;

    protected static ?string $slug = 'meldingen';

    protected static ?string $navigationLabel = 'Meldingen';

    protected static ?string $title = 'Meldingen';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dagelijkse digest')->columns(2)->schema([
                TextInput::make('digest_time')->label('Tijdstip (werkdagen)')->helperText('Feestdagen tellen niet als werkdag. Een nieuw tijdstip geldt na een herstart van de scheduler.')->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->required(),
                TagsInput::make('digest_recipients')->label('Ontvangers')->nestedRecursiveRules(['email']),
                Toggle::make('digest_to_candidates')->label('Ook naar kandidaten (alleen eigen matches)'),
            ]),
            Section::make('Directe meldingen')->columns(3)->schema([
                Toggle::make('direct_mail')->label('Ook per mail (naast de melding in de app)')->columnSpanFull(),
                TextInput::make('urgent_deadline_hours')->label('Sterke match is urgent bij deadline binnen (uur)')->numeric()->minValue(1)->required(),
                TextInput::make('reminder_deadline_hours')->label('Herinnering shortlist bij deadline binnen (uur)')->numeric()->minValue(1)->required(),
            ]),
            Section::make('Microsoft Teams (optioneel)')->schema([
                TextInput::make('teams_webhook_url')->label('Workflows-webhook-URL')->url()->nullable(),
            ]),
        ]);
    }
}
