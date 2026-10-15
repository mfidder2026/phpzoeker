<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\Matching\Jobs\RescoreMatchesJob;
use App\Domain\Matching\Support\WeightAdvisor;
use App\Settings\MatchingSettings;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('applyAdvice')
                ->label('Voorstel overnemen')
                ->icon(Heroicon::OutlinedLightBulb)
                ->color('gray')
                ->visible(fn (): bool => app(WeightAdvisor::class)->advise()['ready'])
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Nieuwe gewichten: '.collect(app(WeightAdvisor::class)->advise()['components'])->map(fn (array $component): string => $component['label'].' '.$component['suggested'])->implode(', ').'. Daarna worden alle scores opnieuw berekend, zonder AI.')
                ->action(function (): void {
                    $settings = app(MatchingSettings::class);
                    $settings->weights = app(WeightAdvisor::class)->suggestedWeights();
                    $settings->save();
                    $this->afterSave();
                    $this->fillForm();
                }),
        ];
    }

    /**
     * Gewichten of drempels gewijzigd → alleen scores en labels opnieuw berekenen (§10.6).
     */
    protected function afterSave(): void
    {
        parent::afterSave();

        RescoreMatchesJob::dispatch();

        Notification::make()->info()->title('Scores worden opnieuw berekend')->body('Zonder nieuwe AI-aanroepen.')->send();
    }

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
            Section::make('Voorstel op basis van feedback')->collapsed()
                ->description('Componenten die goed onderscheiden tussen 👍 en 👎 krijgen meer gewicht. Pas toe met de knop rechtsboven.')
                ->schema([View::make('filament.settings.weight-advice')->viewData(['advice' => app(WeightAdvisor::class)->advise()])]),
            Section::make('Gewichten (§10.3)')->description('Samen 100. Een wijziging herberekent alleen de score, zonder nieuwe AI-aanroep.')->columns(4)->schema($weightFields),
            Section::make('Filters')->columns(2)->schema([
                TextInput::make('start_date_tolerance_days')->label('Tolerantie startdatum (dagen)')->numeric()->minValue(0)->required(),
                TextInput::make('embedding_prefilter_threshold')->label('Embedding-prefilterdrempel')->numeric()->minValue(0)->maxValue(1)->step(0.01)->required()
                    ->helperText('Wordt gebruikt zodra embeddings beschikbaar zijn (D29); de prefilter werkt nu met trefwoorden en technologie.'),
            ]),
            Section::make('AI-kosten')->columns(2)->schema([
                TextInput::make('extraction_budget_reserve_percent')->label('Reserve voor extractie (% van het dagbudget)')->numeric()->minValue(0)->maxValue(100)->suffix('%')->required()
                    ->helperText('Matching pauzeert als alleen deze reserve nog over is; extractie gaat voor (§18.3).'),
                TextInput::make('max_assessments_per_run')->label('Max. AI-beoordelingen per herberekening van één kandidaat')->numeric()->minValue(0)->required(),
            ]),
            Section::make('Technologie-aliassen')->description('Namen die als dezelfde technologie tellen, bijv. postgres → postgresql. Kleine letters.')->collapsed()->schema([
                KeyValue::make('technology_aliases')->hiddenLabel()->keyLabel('Alias')->valueLabel('Telt als')->reorderable(false),
            ]),
        ]);
    }
}
