<?php

namespace App\Filament\Resources\Candidates\Tables;

use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Models\Candidate;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CandidatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Naam')->searchable()->sortable()
                    ->description(fn (Candidate $record): string => $record->job_title),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('seniority')->label('Senioriteit'),
                TextColumn::make('city')->label('Woonplaats'),
                TextColumn::make('hourly_rate')->label('Tarief')->money('EUR', locale: 'nl')->sortable(),
                TextColumn::make('hours_per_week')->label('Uren')->suffix(' u'),
                TextColumn::make('available_from')->label('Beschikbaar')->date('d-m-Y')->placeholder('Per direct'),
                TextColumn::make('activeProfile.version')->label('Master-profiel')->prefix('v')->placeholder('Ontbreekt'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(CandidateStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
