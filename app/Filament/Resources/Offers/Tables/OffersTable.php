<?php

namespace App\Filament\Resources\Offers\Tables;

use App\Domain\Candidates\Models\Candidate;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Support\ResponseTerm;
use App\Domain\Offers\Support\Workdays;
use App\Domain\Relations\Models\Organization;
use App\Filament\Resources\Offers\OfferResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Overzicht van het register met de kolommen uit §11.5 en de filters uit FR-AR2.
 */
class OffersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('sent_at is null desc')->orderByDesc('sent_at')->orderByDesc('id'))
            ->persistFiltersInSession()
            ->recordUrl(fn (Offer $record): string => $record->status->isPreparing() ? OfferResource::getUrl('wizard', ['record' => $record]) : OfferResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('sent_at')->label('Aangeboden op')->dateTime('d-m-Y H:i', ResponseTerm::TIMEZONE)->sortable()->placeholder('In voorbereiding'),
                TextColumn::make('candidate.name')->label('Kandidaat')->sortable()->searchable(),
                TextColumn::make('assignment.title')->label('Opdracht')->searchable(['title', 'reference'])->wrap()
                    ->description(fn (Offer $record): ?string => $record->assignment->reference),
                TextColumn::make('assignment.client_name')->label('Eindklant')->placeholder('—')->toggleable(),
                TextColumn::make('via')->label('Via')->state(fn (Offer $record): string => $record->viaName())
                    ->description(fn (Offer $record): ?string => $record->contact->name ?? $record->contact?->email),
                TextColumn::make('channel')->label('Kanaal')->badge(),
                TextColumn::make('offered_rate')->label('Tarief')->money('EUR', locale: 'nl')->sortable()
                    ->tooltip(fn (Offer $record): string => 'Max. tarief opdracht: '.($record->assignment->max_rate !== null ? '€ '.number_format((float) $record->assignment->max_rate, 2, ',', '.') : 'onbekend')),
                TextColumn::make('sentBy.name')->label('Aangeboden door')->placeholder('—')->toggleable(),
                TextColumn::make('expected_response_at')->label('Reactie verwacht')->sortable()
                    ->state(fn (Offer $record): string => ResponseTerm::indicator($record)['label'])
                    ->badge()
                    ->color(fn (Offer $record): string => match (ResponseTerm::indicator($record)['state']) {
                        'ok' => 'success',
                        'today' => 'warning',
                        'late' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('days_open')->label('Dagen open')->alignCenter()
                    ->state(fn (Offer $record): ?string => $record->status->isOpen() && $record->sent_at !== null ? (string) (int) Workdays::between(CarbonImmutable::parse($record->sent_at), CarbonImmutable::now()) : null)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('candidate_id')->label('Kandidaat')->options(fn (): array => Candidate::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->label('Status')->options(OfferStatus::class)->multiple(),
                Filter::make('period')->label('Periode')
                    ->schema([
                        DatePicker::make('from')->label('Aangeboden vanaf'),
                        DatePicker::make('until')->label('Aangeboden tot en met'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('sent_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('sent_at', '<=', $date))),
                SelectFilter::make('organization_id')->label('Opdrachtgever/intermediair')->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Filter::make('client')->label('Eindklant')
                    ->schema([TextInput::make('client')->label('Eindklant')])
                    ->query(fn (Builder $query, array $data): Builder => blank($data['client'] ?? null) ? $query : $query->whereHas('assignment', fn (Builder $assignment) => $assignment->where('client_name', 'like', '%'.$data['client'].'%'))),
                SelectFilter::make('channel')->label('Kanaal')->options(OfferChannel::class),
                SelectFilter::make('sent_by')->label('Aangeboden door')->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('rate')->label('Tarief')
                    ->schema([
                        TextInput::make('min')->label('Tarief vanaf')->numeric(),
                        TextInput::make('max')->label('Tarief tot en met')->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['min'] ?? null), fn (Builder $query) => $query->where('offered_rate', '>=', $data['min']))
                        ->when(filled($data['max'] ?? null), fn (Builder $query) => $query->where('offered_rate', '<=', $data['max']))),
                Filter::make('overdue')->label('Alleen reactie verlopen')->query(fn (Builder $query): Builder => Offer::whereOverdue($query)),
            ])
            ->recordActions([
                ViewAction::make()->label('Bekijken')
                    ->url(fn (Offer $record): string => $record->status->isPreparing() ? OfferResource::getUrl('wizard', ['record' => $record]) : OfferResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
