<?php

namespace App\Filament\Resources\Matches\Pages;

use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Models\CandidateMatch;
use App\Filament\Resources\Assignments\AssignmentResource;
use App\Filament\Resources\Matches\MatchActions;
use App\Filament\Resources\Matches\MatchResource;
use App\Filament\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Matchdetail (§15.5): score-opbouw, harde filters, eisen/wensen-matrix met bewijs uit het
 * master-profiel, waarschuwingen en de acties.
 *
 * @property CandidateMatch $record
 */
class ViewMatch extends ViewRecord
{
    protected static string $resource = MatchResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (Access::isStaff() && $this->record->status === MatchStatus::Nieuw) {
            $this->record->setStatus(MatchStatus::Bekeken);
        }
    }

    public function getTitle(): string
    {
        return $this->record->assignment->title;
    }

    public function getSubheading(): string
    {
        $assignment = $this->record->assignment;

        return collect([
            Access::isStaff() ? $this->record->candidate->name : null,
            $assignment->client_name,
            $assignment->intermediary_name ? 'via '.$assignment->intermediary_name : null,
            $assignment->city,
            $assignment->deadline_at ? 'deadline '.$assignment->deadline_at->format('d-m-Y H:i') : null,
        ])->filter()->implode(' · ');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('detail')->hiddenLabel()->view('filament.matches.detail')->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            MatchActions::offer(),
            MatchActions::shortlist(),
            MatchActions::reject(),
            MatchActions::feedback(CandidateMatch::FEEDBACK_UP),
            MatchActions::feedback(CandidateMatch::FEEDBACK_DOWN),
            MatchActions::interest(CandidateMatch::FEEDBACK_UP),
            MatchActions::interest(CandidateMatch::FEEDBACK_DOWN),
            ActionGroup::make([
                Action::make('assignment')->label('Naar de opdracht')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (): string => AssignmentResource::getUrl('view', ['record' => $this->record->assignment_id]))
                    ->visible(fn (): bool => Access::isStaff()),
                MatchActions::reassess(),
                MatchActions::overrideKnockout(),
            ])->visible(fn (): bool => Access::isStaff()),
        ];
    }
}
