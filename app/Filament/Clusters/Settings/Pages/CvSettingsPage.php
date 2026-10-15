<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Settings\CvSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CvSettingsPage extends BaseSettingsPage
{
    protected static string $settings = CvSettings::class;

    protected static ?string $slug = 'cv';

    protected static ?string $navigationLabel = 'CV';

    protected static ?string $title = 'CV';

    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contactregel (§12.6)')->columns(2)->schema([
                Select::make('contact_line')->label('Contactgegevens in CV')->required()->options([
                    'fremen' => 'Fremen-contact (accountmanager)',
                    'candidate' => 'Contactgegevens van de kandidaat',
                ]),
                TextInput::make('fremen_contact_name')->label('Naam Fremen-contact')->required(),
                TextInput::make('fremen_contact_email')->label('E-mail Fremen-contact')->email()->required(),
                TextInput::make('fremen_contact_phone')->label('Telefoon Fremen-contact'),
            ]),
            Section::make('Privacy-opties (standaard per aanbieding)')->columns(2)->schema([
                Toggle::make('show_birth_date')->label('Geboortedatum tonen')
                    ->helperText('Standaard uit (R4). Alleen aanzetten als een aanvraag er expliciet om vraagt.'),
                Toggle::make('show_city')->label('Woonplaats tonen'),
                Select::make('show_driving_license')->label('Rijbewijs tonen')->required()->options([
                    'always' => 'Altijd',
                    'onsite_hybrid' => 'Bij onsite of hybride',
                    'never' => 'Nooit',
                ]),
            ]),
            Section::make('Opmaak')->schema([
                TextInput::make('footer_text')->label('Voetregel'),
            ]),
        ]);
    }
}
