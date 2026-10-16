<?php

namespace App\Filament\Pages;

use App\Domain\Offers\Models\Offer;
use App\Domain\Reports\Reports;
use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Rapportages (FR-R1, FR-M7): opdrachten per bron per week, matches per kandidaat, de funnel,
 * doorlooptijden, AI-kosten en gemiste kansen, over een te kiezen periode.
 */
class Rapportages extends Page
{
    protected string $view = 'filament.pages.rapportages';

    protected static ?string $slug = 'rapportages';

    protected static ?string $title = 'Rapportages';

    protected static ?string $navigationLabel = 'Rapportages';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::INZICHT;

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /** @var array<int, string> */
    public const PERIODS = [30 => 'Laatste 30 dagen', 90 => 'Laatste 90 dagen', 365 => 'Laatste 12 maanden'];

    public int $days = 90;

    public static function canAccess(): bool
    {
        return Access::hasAny([Role::Beheerder, Role::Accountmanager]);
    }

    public function updatedDays(): void
    {
        $this->days = array_key_exists($this->days, self::PERIODS) ? $this->days : 90;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $reports = app(Reports::class);
        $since = Reports::since($this->days);
        $costSince = Reports::since(min($this->days, 30));

        return [
            'periods' => self::PERIODS,
            'perSource' => $reports->assignmentsPerSource($since),
            'perCandidate' => $reports->matchesPerCandidate($since),
            'funnel' => $reports->funnel($since),
            'leadTimes' => $reports->leadTimes($since),
            'aiCosts' => $reports->aiCosts($costSince),
            'missed' => $reports->missedChances($since),
            'offerUrl' => fn (Offer $offer): string => OfferResource::getUrl('view', ['record' => $offer]),
        ];
    }
}
