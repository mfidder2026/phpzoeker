<?php

namespace App\Filament\Resources\Candidates\Schemas;

use App\Domain\Candidates\Enums\AbsenceType;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\ContractType;
use App\Domain\Candidates\Enums\Seniority;
use App\Filament\Support\Access;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Formulier met alle vaste waardes van een kandidaat (FR-K1–K3). Een gebruiker met de rol
 * Kandidaat kan alleen beschikbaarheid en afwezigheid aanpassen (FR-K8).
 */
class CandidateForm
{
    public static function configure(Schema $schema): Schema
    {
        $staffOnly = fn (): bool => ! Access::isStaff();

        return $schema->components([
            Tabs::make('Kandidaat')->columnSpanFull()->persistTabInQueryString()->tabs([
                Tab::make('Algemeen')->icon('heroicon-o-user')->schema([
                    Grid::make(3)->schema([
                        TextInput::make('name')->label('Naam')->required()->maxLength(255)->disabled($staffOnly)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, ?string $state, Set $set) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
                        TextInput::make('slug')->label('Slug')->required()->unique(ignoreRecord: true)->disabled($staffOnly),
                        Select::make('status')->label('Status')->options(CandidateStatus::class)->required()->default(CandidateStatus::Beschikbaar)->disabled($staffOnly),
                        TextInput::make('job_title')->label('Functietitel')->required()->placeholder('Senior Developer')->disabled($staffOnly),
                        Select::make('seniority')->label('Senioriteit')->options(Seniority::class)->required()->disabled($staffOnly),
                        TagsInput::make('cv_titles')->label('Toegestane CV-titels')->disabled($staffOnly)
                            ->helperText('De CV-tailor kiest alleen uit deze titels (§12.2).'),
                    ]),
                    Section::make('Contact en persoonsgegevens')->columns(3)->schema([
                        TextInput::make('email')->label('E-mail')->email()->disabled($staffOnly),
                        TextInput::make('phone')->label('Telefoon')->tel()->disabled($staffOnly)
                            ->helperText('Versleuteld opgeslagen; gaat nooit naar het taalmodel.'),
                        DatePicker::make('birth_date')->label('Geboortedatum')
                            ->native(false)->displayFormat('d-m-Y')
                            ->visible(fn (): bool => Access::isStaff())
                            ->helperText('Versleuteld. Niet voor matching, niet naar het taalmodel, standaard niet in CV of mail (R4).'),
                        TextInput::make('city')->label('Woonplaats')->disabled($staffOnly),
                        TextInput::make('postcode')->label('Postcode (optioneel)')->maxLength(16)->disabled($staffOnly),
                        TextInput::make('nationality')->label('Nationaliteit')->disabled($staffOnly)
                            ->helperText('Alleen tonen bij screeningseisen.'),
                        Toggle::make('driving_license')->label('Rijbewijs')->disabled($staffOnly),
                        Radio::make('own_transport')->label('Eigen vervoer')->disabled($staffOnly)
                            ->boolean('Ja', 'Nee')->inline()->helperText('Niets gekozen = onbekend.'),
                        KeyValue::make('languages')->label('Talen')->keyLabel('Taal (code)')->valueLabel('Niveau')->disabled($staffOnly),
                    ]),
                    FileUpload::make('photo_path')->label('Pasfoto')
                        ->image()->imageEditor()
                        ->disk('local')->directory('candidates/photos')->visibility('private')
                        ->maxSize(4096)
                        ->disabled($staffOnly),
                ]),
                Tab::make('Beschikbaarheid en tarief')->icon('heroicon-o-calendar-days')->schema([
                    Grid::make(3)->schema([
                        DatePicker::make('available_from')->label('Beschikbaar vanaf')
                            ->native(false)->displayFormat('d-m-Y')
                            ->helperText('Leeg = per direct.'),
                        TextInput::make('hours_per_week')->label('Uren per week')->numeric()->minValue(1)->maxValue(60)->required(),
                        Toggle::make('cc_self_on_offers')->label('Zelf in CC bij aanbiedingen')->disabled($staffOnly)->inline(false),
                        TextInput::make('hourly_rate')->label('Tarief per uur')->numeric()->prefix('€')->minValue(0)->required()->disabled($staffOnly),
                        TextInput::make('min_hourly_rate')->label('Minimumtarief')->numeric()->prefix('€')->minValue(0)->required()->disabled($staffOnly)
                            ->lte('hourly_rate'),
                        TextInput::make('rate_note')->label('Toelichting tarief')->default('excl. btw')->required()->disabled($staffOnly),
                    ]),
                    Repeater::make('absences')->label('Afwezigheden')
                        ->relationship()
                        ->columns(4)
                        ->defaultItems(0)
                        ->addActionLabel('Afwezigheid toevoegen')
                        ->reorderable(false)
                        ->schema([
                            Select::make('type')->label('Type')->options(AbsenceType::class)->required()->default(AbsenceType::Vakantie),
                            DatePicker::make('starts_on')->label('Van')->native(false)->displayFormat('d-m-Y')->required(),
                            DatePicker::make('ends_on')->label('Tot en met')->native(false)->displayFormat('d-m-Y')->required()->afterOrEqual('starts_on'),
                            TextInput::make('note')->label('Notitie'),
                        ]),
                ]),
                Tab::make('Zoekprofiel')->icon('heroicon-o-magnifying-glass')->schema([
                    Grid::make(2)->relationship('searchProfile')->schema([
                        TagsInput::make('roles')->label('Rollen')->disabled($staffOnly),
                        TagsInput::make('include_keywords')->label('Trefwoorden (insluiten)')->disabled($staffOnly),
                        TagsInput::make('exclude_keywords')->label('Trefwoorden (uitsluiten)')->disabled($staffOnly),
                        TagsInput::make('preferred_tech')->label('Gewenste technologieën')->disabled($staffOnly),
                        TagsInput::make('excluded_tech')->label('Uitgesloten technologieën')->disabled($staffOnly),
                        Select::make('accepted_contract_types')->label('Geaccepteerde contractvormen')
                            ->options(ContractType::class)->multiple()->disabled($staffOnly),
                        TextInput::make('min_hours')->label('Minimaal aantal uren')->numeric()->minValue(0)->default(24)->required()->disabled($staffOnly),
                        TextInput::make('max_travel_minutes')->label('Maximale reistijd')->numeric()->minValue(0)->suffix('min')
                            ->helperText('Leeg = geen grens (heel Nederland).')->disabled($staffOnly),
                    ]),
                ]),
            ]),
        ]);
    }
}
