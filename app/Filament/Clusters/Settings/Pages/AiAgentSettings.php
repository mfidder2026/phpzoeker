<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Config\ConnectionTester;
use App\Ai\Jobs\RunConnectionTestJob;
use App\Ai\Providers\ProviderName;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

/**
 * Instellingen → AI-agent (§18.5, FR-AI1–4). Alleen voor de rol Beheerder. De API-sleutel
 * gaat nooit terug naar de browser: het veld is na opslaan leeg en toont alleen de laatste 4 tekens.
 *
 * @property-read Schema $form
 */
class AiAgentSettings extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'ai-agent';

    protected static ?string $navigationLabel = 'AI-agent';

    protected static ?string $title = 'AI-agent';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?int $profileId = null;

    /**
     * Suggesties voor modelnamen; het veld blijft vrij invulbaar (nieuwe modellen zonder codewijziging).
     *
     * @var list<string>
     */
    private const MODEL_SUGGESTIONS = [
        'glm-5.2', 'glm-5.3', 'glm-5.3-flash', 'kimi-k2.6', 'kimi-k3', 'gpt-oss:120b', 'gpt-oss:20b',
        'deepseek-v4.1-flash', 'mistral-large-4', 'claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5',
    ];

    public static function canAccess(): bool
    {
        return Access::isBeheerder();
    }

    public function mount(): void
    {
        $profile = AiAgentProfile::active() ?? AiAgentProfile::query()->oldest('id')->first();
        $this->profileId = $profile?->id;

        $this->form->fill($profile !== null ? self::toFormState($profile) : [
            'name' => 'Ollama Cloud — productie',
            'provider' => ProviderName::Ollama->value,
            'base_url' => ProviderName::Ollama->defaultBaseUrl(),
            'timeout_seconds' => 120,
            'max_concurrency' => 3,
            'daily_budget_eur' => 10,
            'web_research_enabled' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toFormState(AiAgentProfile $profile): array
    {
        return [
            'name' => $profile->name,
            'provider' => $profile->provider->value,
            'api_key' => null,
            'base_url' => $profile->base_url,
            'extra_options' => $profile->extra_options ?? [],
            'models' => $profile->models,
            'max_output_tokens' => $profile->max_output_tokens ?? [],
            'timeout_seconds' => $profile->timeout_seconds,
            'max_concurrency' => $profile->max_concurrency,
            'daily_budget_eur' => $profile->daily_budget_eur,
            'prices' => collect($profile->prices ?? [])->map(fn (array $price, string $model): array => [
                'model' => $model,
                'input' => $price['input'],
                'output' => $price['output'],
            ])->values()->all(),
            'web_research_enabled' => $profile->web_research_enabled,
            'web_allowed_domains' => $profile->web_allowed_domains ?? [],
            'web_test_url' => $profile->web_test_url,
            'dpa_confirmed' => $profile->dpa_confirmed_at !== null,
            'is_active' => $profile->is_active,
            'is_fallback' => $profile->is_fallback,
        ];
    }

    public function form(Schema $schema): Schema
    {
        $profile = $this->profile();

        $modelFields = [];

        foreach (AiTask::modelTasks() as $task) {
            $modelFields[] = TextInput::make("models.{$task->value}")
                ->label('Model '.mb_strtolower($task->getLabel()))
                ->datalist(self::MODEL_SUGGESTIONS)
                ->required();
            $modelFields[] = TextInput::make("max_output_tokens.{$task->value}")
                ->label('Max. outputtokens')
                ->numeric()->minValue(16)->maxValue(64000)
                ->placeholder((string) $task->defaultMaxOutputTokens());
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Provider en sleutel')->columns(2)->schema([
                    TextInput::make('name')->label('Profielnaam')->required()->maxLength(100),
                    Select::make('provider')->label('Provider')->required()
                        ->options(collect(ProviderName::cases())->mapWithKeys(fn (ProviderName $provider): array => [$provider->value => $provider->getLabel()]))
                        ->live()
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('base_url', ProviderName::tryFrom((string) $state)?->defaultBaseUrl())),
                    TextInput::make('api_key')->label('API-sleutel')
                        ->password()
                        ->autocomplete('new-password')
                        ->placeholder($profile?->maskedApiKey() ?: 'Nog geen sleutel opgeslagen')
                        ->helperText($profile?->api_key_last4 !== null
                            ? 'Opgeslagen sleutel eindigt op '.$profile->api_key_last4.'. Laat leeg om te behouden; vul in om te vervangen.'
                            : 'Wordt versleuteld opgeslagen en daarna nooit meer volledig getoond.')
                        ->dehydrated(fn (?string $state): bool => filled($state)),
                    TextInput::make('base_url')->label('Basis-URL / endpoint')->url()
                        ->helperText('Bijv. https://ollama.com voor Ollama Cloud, of een eigen endpoint.'),
                    KeyValue::make('extra_options')->label('Extra opties')->columnSpanFull()
                        ->keyLabel('Optie')->valueLabel('Waarde')
                        ->helperText('Bijv. api_version (Anthropic), organization (OpenAI) of think=true (Ollama). Versleuteld opgeslagen.'),
                ]),
                Section::make('Modellen per taak')->description('Per taak een eigen model (ADR-06). Vrij in te vullen.')->columns(2)->schema($modelFields),
                Section::make('Limieten, budget en prijzen')->columns(3)->schema([
                    TextInput::make('timeout_seconds')->label('Timeout')->numeric()->minValue(5)->maxValue(900)->suffix('s')->required(),
                    TextInput::make('max_concurrency')->label('Max. gelijktijdige AI-taken')->numeric()->minValue(1)->maxValue(20)->required(),
                    TextInput::make('daily_budget_eur')->label('Dagbudget')->numeric()->minValue(0)->prefix('€')->required(),
                    Repeater::make('prices')->label('Prijzen per 1 mln. tokens (handmatig bijhouden)')->columnSpanFull()
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel('Prijs toevoegen')
                        ->schema([
                            TextInput::make('model')->label('Model (* = standaard)')->required()->datalist(self::MODEL_SUGGESTIONS),
                            TextInput::make('input')->label('Input')->numeric()->minValue(0)->prefix('€')->required(),
                            TextInput::make('output')->label('Output')->numeric()->minValue(0)->prefix('€')->required(),
                        ]),
                ]),
                Section::make('Webonderzoek')->columns(2)->schema([
                    Toggle::make('web_research_enabled')->label('Webonderzoek toestaan')->columnSpanFull(),
                    TagsInput::make('web_allowed_domains')->label('Toegestane domeinen')
                        ->helperText('Alleen deze domeinen (en subdomeinen) mag de app ophalen. Later aangevuld met domeinen van actieve bronnen en relaties.'),
                    TextInput::make('web_test_url')->label('Test-URL')->url()
                        ->helperText('Openbare pagina op een toegestaan domein; gebruikt in stap 4 van de verbindingstest.'),
                ]),
                Section::make('Verwerkersovereenkomst en activatie')->columns(2)->schema([
                    Toggle::make('dpa_confirmed')->label('Verwerkersovereenkomst met deze provider is aanwezig')->live()
                        ->helperText($profile?->dpa_confirmed_at !== null ? 'Bevestigd op '.$profile->dpa_confirmed_at->format('d-m-Y H:i').'.' : 'Vereist voordat het profiel actief kan worden (§19.2).'),
                    Toggle::make('is_active')->label('Actief profiel')
                        ->disabled(fn (Get $get): bool => ! $get('dpa_confirmed'))
                        ->helperText('Precies één profiel is actief.'),
                    Toggle::make('is_fallback')->label('Fallbackprofiel')
                        ->disabled(fn (Get $get): bool => ! $get('dpa_confirmed') || (bool) $get('is_active'))
                        ->helperText('Wordt gebruikt als de provider van het actieve profiel niet bereikbaar of overbelast is (FR-AI9).'),
                ]),
                Section::make('Laatste verbindingstest')->schema([
                    View::make('filament.ai.last-test-result')->viewData(['profile' => $profile]),
                ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Opslaan')->submit('save')->keyBindings(['mod+s']),
                        $this->testConnectionAction()->color('gray'),
                    ]),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->testConnectionAction(),
            Action::make('switchProfile')
                ->label('Ander profiel')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->schema([
                    Select::make('profile')->label('Profiel')->required()
                        ->options(fn (): array => AiAgentProfile::query()->orderByDesc('is_active')->orderBy('name')->get()
                            ->mapWithKeys(fn (AiAgentProfile $profile): array => [$profile->id => $profile->name.($profile->is_active ? ' (actief)' : ($profile->is_fallback ? ' (fallback)' : ''))])->all())
                        ->placeholder('Nieuw profiel'),
                ])
                ->action(function (array $data): void {
                    $profile = filled($data['profile'] ?? null) ? AiAgentProfile::query()->find((int) $data['profile']) : null;
                    $this->profileId = $profile?->id;
                    $this->form->fill($profile !== null ? self::toFormState($profile) : ['name' => 'Fallback', 'timeout_seconds' => 120, 'max_concurrency' => 3, 'daily_budget_eur' => 10]);
                }),
            Action::make('newProfile')
                ->label('Nieuw profiel')
                ->icon(Heroicon::OutlinedPlus)
                ->color('gray')
                ->action(function (): void {
                    $this->profileId = null;
                    $this->form->fill(['name' => 'Fallback', 'provider' => ProviderName::Anthropic->value, 'timeout_seconds' => 120, 'max_concurrency' => 3, 'daily_budget_eur' => 10]);
                }),
            $this->deleteProfileAction(),
        ];
    }

    /**
     * Een niet-actief profiel verwijderen (AiAgentProfilePolicy); AI-taken houden hun historie zonder koppeling.
     */
    public function deleteProfileAction(): Action
    {
        return Action::make('deleteProfile')
            ->label('Profiel verwijderen')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Profiel "'.$this->profile()?->name.'" verwijderen')
            ->modalDescription('Het profiel en de versleutelde API-sleutel worden verwijderd. Het actieve profiel kan niet worden verwijderd.')
            ->visible(fn (): bool => ($profile = $this->profile()) !== null && (Access::user()?->can('delete', $profile) ?? false))
            ->action(function (): void {
                $profile = $this->profile();

                if ($profile === null || ! (Access::user()?->can('delete', $profile) ?? false)) {
                    return;
                }

                $profile->delete();

                $this->mount();

                Notification::make()->title('Profiel verwijderd')->success()->send();
            });
    }

    public function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Verbinding testen')
            ->icon(Heroicon::OutlinedSignal)
            ->action(fn () => $this->testConnection());
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $profile = $this->profile() ?? new AiAgentProfile;
        $keyReplaced = filled($state['api_key'] ?? null);

        $attributes = self::attributesFromState($state);

        if ($keyReplaced) {
            $attributes['api_key'] = $state['api_key'];
        }

        $attributes['dpa_confirmed_at'] = ($state['dpa_confirmed'] ?? false)
            ? ($profile->dpa_confirmed_at ?? now())
            : null;
        $attributes['is_active'] = ($state['dpa_confirmed'] ?? false) && ($state['is_active'] ?? false);
        $attributes['is_fallback'] = ($state['dpa_confirmed'] ?? false) && ($state['is_fallback'] ?? false) && ! $attributes['is_active'];
        $attributes['updated_by'] = Access::user()?->id;

        $profile->fill($attributes)->save();
        $this->profileId = $profile->id;

        if ($keyReplaced) {
            activity('ai-agent')->causedBy(Access::user())->performedOn($profile)
                ->withProperties(['laatste_4' => $profile->api_key_last4])
                ->log('API-sleutel vervangen');
        }

        $this->form->fill(self::toFormState($profile->refresh()));

        Notification::make()->success()->title('AI-agent opgeslagen')->send();

        if ($profile->last_test_result === null || ! ($profile->last_test_result['passed'] ?? false)) {
            Notification::make()->warning()->title('Let op: de verbinding is nog niet met succes getest')
                ->body('Klik op "Verbinding testen" om de instellingen te controleren.')->send();
        }
    }

    /**
     * Test de waarden zoals ze nu in het formulier staan, ook vóór opslaan (FR-AI2).
     */
    public function testConnection(): void
    {
        $state = $this->form->getState();
        $profile = $this->profile();

        $attributes = self::attributesFromState($state);
        $attributes['api_key'] = filled($state['api_key'] ?? null) ? $state['api_key'] : $profile?->api_key;

        $run = RunTracker::queue(
            task: AiTask::ConnectionTest,
            agent: ConnectionTester::class,
            subject: $profile,
            startedBy: Access::user(),
            steps: ConnectionTester::STEPS,
            subjectLabel: 'Verbindingstest — '.$attributes['name'],
            profile: $profile,
        );

        // Alleen bijwerken als de geteste waarden ook de opgeslagen waarden zijn.
        $testsSavedProfile = $profile !== null && ! filled($state['api_key'] ?? null);

        RunConnectionTestJob::dispatch($run->id(), $attributes, $testsSavedProfile ? $profile->id : null);

        $this->dispatch('ai-run-started', runId: $run->id());
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function attributesFromState(array $state): array
    {
        $prices = [];

        foreach ((array) ($state['prices'] ?? []) as $row) {
            if (filled($row['model'] ?? null)) {
                $prices[(string) $row['model']] = ['input' => (float) $row['input'], 'output' => (float) $row['output']];
            }
        }

        return [
            'name' => $state['name'],
            'provider' => $state['provider'],
            'base_url' => $state['base_url'] ?? null,
            'extra_options' => array_filter((array) ($state['extra_options'] ?? []), fn (mixed $value): bool => filled($value)),
            'models' => array_map('strval', (array) ($state['models'] ?? [])),
            'max_output_tokens' => array_map('intval', array_filter((array) ($state['max_output_tokens'] ?? []), fn (mixed $value): bool => filled($value))),
            'timeout_seconds' => (int) $state['timeout_seconds'],
            'max_concurrency' => (int) $state['max_concurrency'],
            'daily_budget_eur' => (float) $state['daily_budget_eur'],
            'prices' => $prices,
            'web_research_enabled' => (bool) ($state['web_research_enabled'] ?? false),
            'web_allowed_domains' => array_values(Arr::wrap($state['web_allowed_domains'] ?? [])),
            'web_test_url' => $state['web_test_url'] ?? null,
        ];
    }

    private function profile(): ?AiAgentProfile
    {
        return $this->profileId !== null ? AiAgentProfile::query()->find($this->profileId) : null;
    }
}
