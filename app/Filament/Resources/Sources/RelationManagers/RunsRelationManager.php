<?php

namespace App\Filament\Resources\Sources\RelationManagers;

use App\Domain\Sources\Models\SourceRun;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Run-log per bron (FR-B3).
 */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Runs';

    protected static ?string $modelLabel = 'run';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('started_at')->label('Gestart')->dateTime('d-m-Y H:i:s'),
            TextEntry::make('status')->label('Status')->badge(),
            TextEntry::make('log')->label('Log')->placeholder('Geen meldingen')->columnSpanFull()
                ->extraAttributes(['style' => 'white-space: pre-line; font-family: ui-monospace, monospace; font-size: .8rem']),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('started_at')->label('Gestart')->dateTime('d-m-Y H:i:s'),
                TextColumn::make('duration')->label('Duur')->state(fn (SourceRun $record): ?string => $record->finished_at ? $record->started_at->diffInSeconds($record->finished_at).' s' : null)->placeholder('bezig'),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('fetched_count')->label('Opgehaald'),
                TextColumn::make('new_count')->label('Nieuw'),
                TextColumn::make('error_count')->label('Fouten')->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('log')->label('Log')->limit(80)->placeholder('—'),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
