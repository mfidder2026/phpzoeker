<?php

namespace App\Filament\Resources\Sources\Tables;

use App\Domain\Sources\Models\Source;
use App\Filament\Resources\Sources\SourceActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Bron')->searchable()->sortable()
                    ->description(fn (Source $record): string => (string) $record->access_method),
                TextColumn::make('type')->label('Type')->badge()->color('gray'),
                IconColumn::make('enabled')->label('Aan')->boolean(),
                TextColumn::make('tos_reviewed_at')->label('ToS-check')->date('d-m-Y')->placeholder('Ontbreekt')->color(fn (Source $record): string => $record->hasToSCheck() ? 'success' : 'warning'),
                TextColumn::make('poll_interval_minutes')->label('Interval')->suffix(' min'),
                TextColumn::make('last_run_at')->label('Laatste run')->since()->dateTimeTooltip('d-m-Y H:i')->placeholder('—'),
                TextColumn::make('raw_postings_count')->label('Publicaties')->counts('rawPostings'),
                TextColumn::make('health')->label('Status')->badge()
                    ->state(fn (Source $record): string => match (true) {
                        ! $record->enabled => 'Uit',
                        $record->health_warning !== null => 'Waarschuwing',
                        $record->consecutive_failures > 0 => $record->consecutive_failures.'× fout',
                        default => 'Actief',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Actief' => 'success',
                        'Uit' => 'gray',
                        default => 'danger',
                    })
                    ->tooltip(fn (Source $record): ?string => $record->health_warning),
            ])
            ->recordActions([
                SourceActions::testRun(),
                SourceActions::runNow(),
                EditAction::make(),
            ]);
    }
}
