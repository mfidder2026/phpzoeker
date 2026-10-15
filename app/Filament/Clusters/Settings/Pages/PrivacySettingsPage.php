<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Settings\PrivacySettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PrivacySettingsPage extends BaseSettingsPage
{
    protected static string $settings = PrivacySettings::class;

    protected static ?string $slug = 'privacy';

    protected static ?string $navigationLabel = 'Privacy';

    protected static ?string $title = 'Privacy en bewaartermijnen';

    protected static ?int $navigationSort = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public function form(Schema $schema): Schema
    {
        $field = fn (string $name, string $label, string $unit): TextInput => TextInput::make($name)->label($label)->numeric()->minValue(1)->suffix($unit)->required();

        return $schema->components([
            Section::make('Bewaartermijnen (§19.3)')->columns(2)->schema([
                $field('raw_postings_days', 'Raw postings', 'dagen'),
                $field('closed_assignments_months', 'Gesloten opdrachten zonder aanbieding', 'maanden'),
                $field('matches_months', 'Matches zonder aanbieding', 'maanden'),
                $field('offers_months', 'Aanbiedingen, snapshots, CV\'s en mails', 'maanden'),
                $field('contacts_months', 'Contactpersonen zonder aanbieding', 'maanden'),
                $field('ai_runs_months', 'AI-taken (zonder inhoud)', 'maanden'),
                $field('web_research_days', 'Resultaten website-onderzoek', 'dagen'),
            ]),
        ]);
    }
}
