<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Settings\RegisterSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class RegisterSettingsPage extends BaseSettingsPage
{
    protected static string $settings = RegisterSettings::class;

    protected static ?string $slug = 'register';

    protected static ?string $navigationLabel = 'Register';

    protected static ?string $title = 'Aanbiedingsregister';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reactietermijnen (§11.6)')->description('In werkdagen; weekenden en Nederlandse feestdagen tellen niet mee.')->columns(2)->schema([
                TextInput::make('default_response_term_days')->label('Standaard reactietermijn')->numeric()->minValue(1)->suffix('werkdagen')->required(),
                TextInput::make('follow_up_term_days')->label('Nieuwe termijn na opvolging')->numeric()->minValue(1)->suffix('werkdagen')->required(),
                TextInput::make('no_response_after_follow_ups')->label('Voorstel "geen reactie" na')->numeric()->minValue(1)->suffix('opvolgingen')->required(),
                TextInput::make('no_response_after_workdays')->label('… en na')->numeric()->minValue(1)->suffix('werkdagen')->required(),
                TextInput::make('expected_response_time')->label('Tijdstip verwachte reactie')->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->required(),
            ]),
        ]);
    }
}
