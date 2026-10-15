<?php

namespace App\Filament\Resources\Assignments\Tables;

use App\Domain\Assignments\Enums\AssignmentContractType;
use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Enums\Sector;
use App\Domain\Assignments\Enums\WorkMode;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Sources\Models\Source;
use App\Filament\Resources\Assignments\AssignmentActions;
use Carbon\CarbonInterface;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Opdrachtenlijst met de filters uit FR-O6, inclusief beste score en kandidaat.
 */
class AssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('sources')->with(['matches.candidate']))
            ->columns([
                TextColumn::make('title')->label('Opdracht')->searchable(['title', 'client_name', 'intermediary_name', 'reference'])->wrap()
                    ->weight('semibold')
                    ->description(fn (Assignment $record): string => collect([$record->client_name, $record->intermediary_name ? 'via '.$record->intermediary_name : null, $record->reference ? 'ref. '.$record->reference : null])->filter()->implode(' · ')),
                TextColumn::make('city')->label('Plaats')->placeholder('—')->description(fn (Assignment $record): ?string => $record->province),
                TextColumn::make('work_mode')->label('Werkvorm')->badge()->color('gray'),
                TextColumn::make('hours')->label('Uren')->state(fn (Assignment $record): ?string => match (true) {
                    $record->hours_min === null && $record->hours_max === null => null,
                    $record->hours_min === $record->hours_max || $record->hours_max === null => $record->hours_min.' u',
                    default => $record->hours_min.'–'.$record->hours_max.' u',
                })->placeholder('—'),
                TextColumn::make('max_rate')->label('Max. tarief')->money('EUR', locale: 'nl')->placeholder('—')->sortable(),
                TextColumn::make('contract_type')->label('Contract')->badge()->color('gray')->toggleable(),
                TextColumn::make('deadline_at')->label('Deadline')->dateTime('d-m-Y H:i')->sortable()->placeholder('—')
                    ->description(fn (Assignment $record): ?string => $record->deadline_at === null ? null : ($record->deadline_at->isPast() ? 'verlopen' : 'nog '.$record->deadline_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)))
                    ->color(fn (Assignment $record): ?string => $record->deadline_at !== null && $record->deadline_at->isFuture() && $record->deadline_at->lt(now()->addDay()) ? 'danger' : null),
                TextColumn::make('best_matches')->label('Matches')->badge()
                    ->state(fn (Assignment $record): array => $record->matches->sortByDesc('score')
                        ->map(fn (CandidateMatch $match): string => $match->candidate->initials().' '.$match->score)->values()->all())
                    ->color(fn (string $state, Assignment $record): string => $record->matches->first(fn (CandidateMatch $match): bool => $match->candidate->initials().' '.$match->score === $state)?->label->getColor() ?? 'gray')
                    ->placeholder('—'),
                TextColumn::make('sources_count')->label('Bronnen')->alignCenter(),
                TextColumn::make('status')->label('Status')->badge(),
                IconColumn::make('is_relevant')->label('Relevant')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Binnen')->since()->dateTimeTooltip('d-m-Y H:i')->sortable()->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_relevant')->label('Relevant')->default(true)
                    ->trueLabel('Alleen softwareontwikkeling')->falseLabel('Alleen niet relevant')->placeholder('Alles'),
                SelectFilter::make('status')->label('Status')->options(AssignmentStatus::class)->multiple()
                    ->default([AssignmentStatus::Nieuw->value, AssignmentStatus::Gematcht->value, AssignmentStatus::Shortlist->value]),
                SelectFilter::make('source')->label('Bron')
                    ->options(fn (): array => Source::query()->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereHas('sources', fn (Builder $sources) => $sources->where('source_id', $data['value']))),
                SelectFilter::make('province')->label('Provincie')
                    ->options(fn (): array => Assignment::query()->whereNotNull('province')->distinct()->orderBy('province')->pluck('province', 'province')->all()),
                SelectFilter::make('work_mode')->label('Werkvorm')->options(WorkMode::class),
                SelectFilter::make('contract_type')->label('Contractvorm')->options(AssignmentContractType::class),
                SelectFilter::make('sector')->label('Sector')->options(Sector::class),
                Filter::make('deadline_soon')->label('Deadline binnen 7 dagen')
                    ->query(fn (Builder $query): Builder => $query->whereBetween('deadline_at', [now(), now()->addDays(7)])),
                SelectFilter::make('candidate')->label('Match met kandidaat')
                    ->options(fn (): array => Candidate::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereHas('matches', fn (Builder $matches) => $matches->where('candidate_id', $data['value'])->where('hard_filter_passed', true)->whereIn('label', [MatchLabel::Sterk->value, MatchLabel::Mogelijk->value]))),
                Filter::make('min_score')->label('Score 60 of hoger')
                    ->query(fn (Builder $query): Builder => $query->whereHas('matches', fn (Builder $matches) => $matches->where('score', '>=', 60))),
                Filter::make('possible_duplicate')->label('Mogelijk duplicaat')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('possible_duplicate_of_id')),
            ])
            ->recordActions([
                ViewAction::make()->label('Bekijken'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([AssignmentActions::ignoreBulk()]),
            ]);
    }
}
