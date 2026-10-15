<?php

namespace App\Filament\Resources\AiRuns;

use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiRunStatus;
use App\Ai\Runs\AiTask;
use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Resources\AiRuns\Pages\ListAiRuns;
use App\Filament\Resources\AiRuns\Pages\ViewAiRun;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Pagina "AI-activiteit" (§15.4, FR-AI7): lopende, wachtende en afgeronde AI-taken.
 */
class AiRunResource extends Resource
{
    protected static ?string $model = AiRun::class;

    protected static ?string $slug = 'ai-activiteit';

    protected static ?string $modelLabel = 'AI-taak';

    protected static ?string $pluralModelLabel = 'AI-taken';

    protected static ?string $navigationLabel = 'AI-activiteit';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::INZICHT;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    public static function canAccess(): bool
    {
        return Access::hasAny([Role::Beheerder, Role::Accountmanager, Role::Reviewer]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(
            ! Access::isBeheerder(),
            fn (Builder $query) => $query->where('started_by', Access::user()->id ?? 0),
        );
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereIn('status', [AiRunStatus::Queued->value, AiRunStatus::Running->value])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'primary';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Lopende en wachtende AI-taken';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('3s')
            ->columns([
                TextColumn::make('created_at')->label('Gestart')->dateTime('d-m-Y H:i:s')->sortable(),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('task')->label('Taak')->badge()->color('gray'),
                TextColumn::make('subject_label')->label('Onderwerp')->wrap()->searchable()->placeholder('—'),
                TextColumn::make('agent')->label('Agent')->formatStateUsing(fn (string $state): string => class_basename($state))->toggleable(),
                TextColumn::make('progress')->label('Voortgang')->suffix('%')->placeholder('—'),
                TextColumn::make('duration')->label('Duur')->state(fn (AiRun $record): ?string => $record->durationSeconds() !== null ? $record->durationSeconds().' s' : null)->placeholder('—'),
                TextColumn::make('model')->label('Model')->placeholder('—')->toggleable(),
                TextColumn::make('tokens')->label('Tokens')->state(fn (AiRun $record): string => number_format($record->input_tokens, 0, ',', '.').' / '.number_format($record->output_tokens, 0, ',', '.')),
                TextColumn::make('cost_eur')->label('Kosten')->money('EUR', decimalPlaces: 4, locale: 'nl')->sortable(),
                TextColumn::make('starter.name')->label('Gestart door')->placeholder('Systeem')->toggleable(),
                TextColumn::make('error_message')->label('Fout')->limit(60)->color('danger')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(AiRunStatus::class),
                SelectFilter::make('task')->label('Taak')->options(AiTask::class),
                SelectFilter::make('agent')->label('Agent')->options(fn (): array => AiRun::query()->distinct()->pluck('agent')
                    ->mapWithKeys(fn (string $agent): array => [$agent => class_basename($agent)])->all()),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('AI-taak')->columns(3)->schema([
                TextEntry::make('subject_label')->label('Onderwerp')->placeholder('—')->columnSpanFull(),
                TextEntry::make('status')->label('Status')->badge(),
                TextEntry::make('task')->label('Taak')->badge(),
                TextEntry::make('agent')->label('Agent')->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextEntry::make('model')->label('Model')->placeholder('—'),
                TextEntry::make('prompt_version')->label('Promptversie')->placeholder('—'),
                TextEntry::make('starter.name')->label('Gestart door')->placeholder('Systeem'),
                TextEntry::make('queued_at')->label('In wachtrij')->dateTime('d-m-Y H:i:s'),
                TextEntry::make('started_at')->label('Gestart')->dateTime('d-m-Y H:i:s')->placeholder('—'),
                TextEntry::make('finished_at')->label('Klaar')->dateTime('d-m-Y H:i:s')->placeholder('—'),
                TextEntry::make('input_tokens')->label('Inputtokens')->numeric(locale: 'nl'),
                TextEntry::make('output_tokens')->label('Outputtokens')->numeric(locale: 'nl'),
                TextEntry::make('cost_eur')->label('Geschatte kosten')->money('EUR', decimalPlaces: 4, locale: 'nl'),
                TextEntry::make('error_message')->label('Foutmelding')->placeholder('—')->color('danger')->columnSpanFull(),
            ]),
            Section::make('Stappen')->schema([
                ViewEntry::make('steps')->hiddenLabel()->view('filament.ai.run-steps'),
            ]),
        ]);
    }

    public static function getWidgets(): array
    {
        return [Widgets\AiActivityStats::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiRuns::route('/'),
            'view' => ViewAiRun::route('/{record}'),
        ];
    }
}
