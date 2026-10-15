<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Settings\MatchingSettings;
use BackedEnum;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MatchingSettingsPage extends BaseSettingsPage
{
    protected static string $settings = MatchingSettings::class;

    protected static ?string $slug = 'matching';

    protected static ?string $navigationLabel = 'Matching';

    protected static ?string $title = 'Matching';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    /**
     * @var array<string, string>
     */
    private const WEIGHTS = [
        'requirements' => 'Eisen',
        'wishes' => 'Wensen',
        'technology' => 'Technologie-overlap',
        'seniority' => 'Senioriteit',
        'rate' => 'Tarief',
        'location' => 'Locatie/reistijd',
        'hours' => 'Uren/start/duur',
    ];

    public function form(Schema $schema): Schema
    {
        $weightFields = [];

        foreach (self::WEIGHTS as $key => $label) {
            $weightFields[] = TextInput::make("weights.{$key}")->label($label)->numeric()->minValue(0)->maxValue(100)->required()
                ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    $sum = collect(array_keys(self::WEIGHTS))->sum(fn (string $weight): int => (int) $get("weights.{$weight}"));

                    if ($sum !== 100) {
                        $fail("De gewichten tellen op tot {$sum}; dat moet 100 zijn.");
                    }
                }]);
        }

        return $schema->components([
            Section::make('Labels')->columns(2)->schema([
                TextInput::make('strong_threshold')->label('Drempel sterke match')->numeric()->minValue(0)->maxValue(100)->required(),
                TextInput::make('possible_threshold')->label('Drempel mogelijke match')->numeric()->minValue(0)->maxValue(100)->required()->lt('strong_threshold'),
            ]),
            Section::make('Gewichten (§10.3)')->description('Samen 100. Een wijziging herberekent alleen de score, zonder nieuwe AI-aanroep.')->columns(4)->schema($weightFields),
            Section::make('Filters')->columns(2)->schema([
                TextInput::make('start_date_tolerance_days')->label('Tolerantie startdatum (dagen)')->numeric()->minValue(0)->required(),
                TextInput::make('embedding_prefilter_threshold')->label('Embedding-prefilterdrempel')->numeric()->minValue(0)->maxValue(1)->step(0.01)->required(),
            ]),
        ]);
    }
}
