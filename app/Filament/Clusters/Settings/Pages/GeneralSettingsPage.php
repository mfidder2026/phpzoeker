<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Settings\GeneralSettings;
use BackedEnum;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class GeneralSettingsPage extends BaseSettingsPage
{
    protected static string $settings = GeneralSettings::class;

    protected static ?string $slug = 'algemeen';

    protected static ?string $navigationLabel = 'Algemeen';

    protected static ?string $title = 'Algemeen';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organisatie')->columns(2)->schema([
                TextInput::make('organization_name')->label('Organisatie')->required(),
                TextInput::make('admin_email')->label('Beheeradres')->email()->required()
                    ->helperText('Gebruikt voor beheermeldingen en in de user agent van de app.'),
            ]),
            Section::make('Opdrachten')->columns(2)->schema([
                TextInput::make('default_deadline_time')->label('Tijdstip bij deadline zonder tijd')
                    ->required()->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->placeholder('12:00'),
                TagsInput::make('countries')->label('Landen')->helperText('Landfilter (ISO-code). v1: alleen NL.'),
            ]),
        ]);
    }
}
