<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Support\ResponseTerm;
use App\Filament\Resources\Offers\OfferResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Alle aanbiedingen aan deze relatie: een gefilterde weergave van het register (§11.9).
 */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $title = 'Aanbiedingen';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sent_at', 'desc')
            ->recordUrl(fn (Offer $record): string => OfferResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('sent_at')->label('Aangeboden op')->dateTime('d-m-Y H:i', ResponseTerm::TIMEZONE)->placeholder('In voorbereiding')->sortable(),
                TextColumn::make('candidate.name')->label('Kandidaat'),
                TextColumn::make('assignment.title')->label('Opdracht')->wrap(),
                TextColumn::make('offered_rate')->label('Tarief')->money('EUR', locale: 'nl'),
                TextColumn::make('response_time_workdays')->label('Reactietijd')->suffix(' wd')->placeholder('—'),
                TextColumn::make('status')->label('Status')->badge(),
            ]);
    }
}
