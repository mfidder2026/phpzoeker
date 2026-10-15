<?php

namespace App\Filament\Resources\Assignments\Schemas;

use App\Domain\Assignments\Enums\AssignmentContractType;
use App\Domain\Assignments\Enums\Sector;
use App\Domain\Assignments\Enums\WorkMode;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Handmatig corrigeren van geëxtraheerde velden (FR-O7). Gewijzigde velden worden vergrendeld.
 */
class AssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        $categories = ['techniek' => 'Techniek', 'ervaring' => 'Ervaring', 'opleiding' => 'Opleiding', 'certificaat' => 'Certificaat', 'screening' => 'Screening', 'taal' => 'Taal', 'competentie' => 'Competentie', 'overig' => 'Overig'];

        return $schema->components([
            Section::make('Opdracht')->description('Velden die je hier wijzigt, worden vergrendeld: een nieuwe extractie overschrijft ze niet.')->columns(3)->schema([
                TextInput::make('title')->label('Titel')->required()->columnSpan(2),
                Toggle::make('is_relevant')->label('Softwareontwikkeling (relevant)')->inline(false),
                TextInput::make('client_name')->label('Eindklant'),
                TextInput::make('intermediary_name')->label('Intermediair'),
                TextInput::make('reference')->label('Referentie'),
                Textarea::make('summary')->label('Samenvatting')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Locatie en werkvorm')->columns(4)->schema([
                TextInput::make('city')->label('Plaats'),
                TextInput::make('province')->label('Provincie'),
                Select::make('work_mode')->label('Werkvorm')->options(WorkMode::class)->required(),
                TextInput::make('onsite_days_per_week')->label('Dagen op locatie')->numeric()->minValue(0)->maxValue(5)->step(0.5),
            ]),
            Section::make('Uren, periode en tarief')->columns(4)->schema([
                TextInput::make('hours_min')->label('Uren min.')->numeric()->minValue(0)->maxValue(80),
                TextInput::make('hours_max')->label('Uren max.')->numeric()->minValue(0)->maxValue(80)->gte('hours_min'),
                Toggle::make('start_asap')->label('Start per direct')->inline(false),
                DatePicker::make('start_date')->label('Startdatum')->native(false)->displayFormat('d-m-Y'),
                DatePicker::make('end_date')->label('Einddatum')->native(false)->displayFormat('d-m-Y'),
                TextInput::make('duration_months')->label('Duur (maanden)')->numeric()->minValue(0),
                TextInput::make('extension_option')->label('Verlenging')->columnSpan(2),
                TextInput::make('max_rate')->label('Max. tarief')->numeric()->prefix('€')->minValue(0),
                Toggle::make('rate_includes_margin')->label('Incl. marge')->inline(false),
                TextInput::make('rate_text')->label('Tarieftekst')->columnSpan(2),
                DateTimePicker::make('deadline_at')->label('Deadline')->native(false)->displayFormat('d-m-Y H:i')->seconds(false),
            ]),
            Section::make('Contract en sector')->columns(4)->schema([
                Select::make('contract_type')->label('Contractvorm')->options(AssignmentContractType::class)->required(),
                Radio::make('zzp_allowed')->label('Zzp toegestaan')->boolean('Ja', 'Nee')->inline(),
                Select::make('sector')->label('Sector')->options(Sector::class)->required(),
                Select::make('seniority')->label('Senioriteit')->options(['junior' => 'Junior', 'medior' => 'Medior', 'senior' => 'Senior', 'lead' => 'Lead', 'onbekend' => 'Onbekend'])->required(),
            ]),
            Section::make('Eisen, wensen en technologie')->schema([
                TagsInput::make('technologies')->label('Technologieën'),
                Repeater::make('requirements')->label('Eisen (knock-out)')->addActionLabel('Eis toevoegen')->collapsible()
                    ->itemLabel(fn (array $state): string => trim(($state['id'] ?? '').' '.mb_substr((string) ($state['tekst'] ?? ''), 0, 80)))
                    ->schema([
                        Grid::make(6)->schema([
                            TextInput::make('id')->label('ID')->required(),
                            Select::make('categorie')->label('Categorie')->options($categories)->required()->columnSpan(2),
                            Toggle::make('knock_out')->label('Knock-out')->default(true)->inline(false),
                            TextInput::make('min_jaren')->label('Min. jaren')->numeric(),
                            TextInput::make('technologie')->label('Technologie'),
                        ]),
                        Textarea::make('tekst')->label('Eis')->rows(2)->required(),
                    ]),
                Repeater::make('wishes')->label('Wensen')->addActionLabel('Wens toevoegen')->collapsible()
                    ->itemLabel(fn (array $state): string => trim(($state['id'] ?? '').' '.mb_substr((string) ($state['tekst'] ?? ''), 0, 80)))
                    ->schema([
                        Grid::make(4)->schema([
                            TextInput::make('id')->label('ID')->required(),
                            Select::make('categorie')->label('Categorie')->options($categories)->required()->columnSpan(2),
                            TextInput::make('gewicht')->label('Gewicht')->numeric(),
                        ]),
                        Textarea::make('tekst')->label('Wens')->rows(2)->required(),
                    ]),
            ]),
        ]);
    }
}
