<?php

namespace App\Filament\Resources\Offers\Widgets;

use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Models\Offer;
use App\Filament\Support\Access;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kengetallen bovenaan het register (FR-AR10).
 */
class OfferStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $user = Access::user();
        $base = fn (): Builder => Offer::query()->when($user !== null && $user->isKandidaat(), fn (Builder $query) => $query->where('candidate_id', $user->candidate_id ?? 0));

        $week = $base()->sent()->where('sent_at', '>=', now()->startOfWeek())->count();
        $month = $base()->sent()->where('sent_at', '>=', now()->startOfMonth())->count();
        $open = $base()->whereIn('status', [OfferStatus::Verzonden->value, OfferStatus::ReactieOntvangen->value, OfferStatus::IntakeGepland->value, OfferStatus::GeenReactie->value])->count();
        $overdue = $base()->overdue()->count();
        $avgResponse = $base()->whereNotNull('response_time_workdays')->avg('response_time_workdays');
        $sent = $base()->sent()->count();
        $placed = $base()->where('status', OfferStatus::Geplaatst->value)->count();
        $rates = $base()->sent()->with('candidate')->get()->groupBy(fn (Offer $offer): string => $offer->candidate->name)
            ->map(fn ($offers, string $name): string => $name.' € '.number_format((float) $offers->avg('offered_rate'), 2, ',', '.'))
            ->implode(' · ');

        return [
            Stat::make('Deze week', (string) $week)->description("{$month} deze maand"),
            Stat::make('Open', (string) $open)->description('verzonden, nog niet afgerond'),
            Stat::make('Reactie verlopen', (string) $overdue)->color($overdue > 0 ? 'danger' : 'gray')->description($overdue > 0 ? 'opvolgen' : 'alles op schema'),
            Stat::make('Gem. reactietijd', $avgResponse === null ? '—' : str_replace('.', ',', (string) round((float) $avgResponse, 1)).' wd')->description('werkdagen tot een reactie'),
            Stat::make('Slagingspercentage', $sent === 0 ? '—' : round($placed / $sent * 100).'%')->description($rates !== '' ? 'gem. tarief: '.$rates : 'geplaatst ÷ aangeboden'),
        ];
    }
}
