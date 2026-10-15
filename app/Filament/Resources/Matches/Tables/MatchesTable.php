<?php

namespace App\Filament\Resources\Matches\Tables;

use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Enums\Recommendation;
use App\Domain\Matching\Models\CandidateMatch;
use App\Filament\Resources\Matches\MatchActions;
use App\Filament\Support\Access;
use Carbon\CarbonInterface;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Matchlijst: standaard sterke en mogelijke matches voor open opdrachten; afgevallen en zwakke
 * matches zijn zichtbaar via het filter Label (§10.2, §10.5).
 */
class MatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('score', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('candidate.name')->label('Kandidaat')->sortable()->weight('semibold')
                    ->visible(fn (): bool => Access::isStaff()),
                TextColumn::make('assignment.title')->label('Opdracht')->searchable()->wrap()
                    ->description(fn (CandidateMatch $record): string => collect([
                        $record->assignment->client_name,
                        $record->assignment->intermediary_name ? 'via '.$record->assignment->intermediary_name : null,
                        $record->assignment->city,
                    ])->filter()->implode(' · ')),
                TextColumn::make('score')->label('Score')->sortable()->badge()->alignCenter()
                    ->color(fn (CandidateMatch $record): string => $record->label->getColor()),
                TextColumn::make('label')->label('Label')->badge(),
                TextColumn::make('recommendation')->label('Advies')->badge()->placeholder('—')->toggleable(),
                TextColumn::make('warnings')->label('Let op')
                    ->state(fn (CandidateMatch $record): ?string => $record->warnings === null || $record->warnings === [] ? null : (string) count($record->warnings))
                    ->icon('heroicon-o-exclamation-triangle')->color('warning')
                    ->tooltip(fn (CandidateMatch $record): ?string => $record->warnings === null ? null : implode("\n", $record->warnings))
                    ->placeholder('—'),
                TextColumn::make('assignment.deadline_at')->label('Deadline')->dateTime('d-m-Y H:i')->sortable()->placeholder('—')
                    ->description(fn (CandidateMatch $record): ?string => $record->assignment->deadline_at === null ? null
                        : ($record->assignment->deadline_at->isPast() ? 'verlopen' : 'nog '.$record->assignment->deadline_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)))
                    ->color(fn (CandidateMatch $record): ?string => $record->assignment->deadline_at !== null && $record->assignment->deadline_at->isFuture() && $record->assignment->deadline_at->lt(now()->addDay()) ? 'danger' : null),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('feedback')->label('Feedback')->alignCenter()->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        CandidateMatch::FEEDBACK_UP => '👍',
                        CandidateMatch::FEEDBACK_DOWN => '👎',
                        default => '—',
                    })
                    ->tooltip(fn (CandidateMatch $record): ?string => $record->feedback_reason)
                    ->visible(fn (): bool => Access::isStaff())
                    ->toggleable(),
                TextColumn::make('candidate_interest')->label('Interesse')->alignCenter()->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        CandidateMatch::FEEDBACK_UP => '👍',
                        CandidateMatch::FEEDBACK_DOWN => '👎',
                        default => '—',
                    })
                    ->tooltip(fn (CandidateMatch $record): ?string => $record->candidate_interest_reason)
                    ->toggleable(),
                TextColumn::make('computed_at')->label('Berekend')->since()->dateTimeTooltip('d-m-Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('candidate_id')->label('Kandidaat')
                    ->options(fn (): array => Candidate::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => Access::isStaff()),
                SelectFilter::make('label')->label('Label')->options(MatchLabel::class)->multiple()
                    ->default([MatchLabel::Sterk->value, MatchLabel::Mogelijk->value]),
                SelectFilter::make('status')->label('Status')->options(MatchStatus::class)->multiple()
                    ->default([MatchStatus::Nieuw->value, MatchStatus::Bekeken->value, MatchStatus::Shortlist->value]),
                SelectFilter::make('recommendation')->label('Advies')->options(Recommendation::class),
                Filter::make('open')->label('Alleen open opdrachten')->default()
                    ->query(fn (Builder $query): Builder => $query->whereHas('assignment', fn (Builder $assignment) => $assignment->whereNotIn('status', [AssignmentStatus::Gesloten->value, AssignmentStatus::Genegeerd->value]))),
                Filter::make('deadline_soon')->label('Deadline binnen 7 dagen')
                    ->query(fn (Builder $query): Builder => $query->whereHas('assignment', fn (Builder $assignment) => $assignment->whereBetween('deadline_at', [now(), now()->addDays(7)]))),
                Filter::make('knockout')->label('Knock-out gefaald')
                    ->query(fn (Builder $query): Builder => $query->where('knockout_failed', true)),
                Filter::make('without_feedback')->label('Zonder feedback')
                    ->query(fn (Builder $query): Builder => $query->whereNull('feedback'))
                    ->visible(fn (): bool => Access::isStaff()),
            ])
            ->recordActions([
                ViewAction::make()->label('Bekijken'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([MatchActions::shortlistBulk(), MatchActions::rejectBulk()]),
            ]);
    }
}
