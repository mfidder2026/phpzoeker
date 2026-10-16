<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Domain\Offers\Actions\ImportOffers;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Support\ResponseTerm;
use App\Filament\Pages\HandmatigRegistreren;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Offers\Widgets\OfferStats;
use App\Filament\Support\Access;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    public function getSubheading(): string
    {
        return 'Elke aanbieding: wie, wanneer, door wie, waar, voor welk tarief, en wanneer we een reactie verwachten.';
    }

    protected function getHeaderWidgets(): array
    {
        return [OfferStats::class];
    }

    /**
     * Snelfilters (§11.5).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $statuses = fn (OfferStatus ...$statuses): array => array_map(fn (OfferStatus $status): string => $status->value, $statuses);

        return [
            'alle' => Tab::make('Alle'),
            'open' => Tab::make('Open')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses(OfferStatus::Verzonden, OfferStatus::ReactieOntvangen, OfferStatus::IntakeGepland, OfferStatus::GeenReactie))),
            'verlopen' => Tab::make('Reactie verlopen')->modifyQueryUsing(fn (Builder $query) => Offer::whereOverdue($query))
                ->badge(fn (): ?int => ($count = Offer::whereOverdue(OfferResource::getEloquentQuery())->count()) > 0 ? $count : null)->badgeColor('danger'),
            'voorbereiding' => Tab::make('In voorbereiding')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses(OfferStatus::Concept, OfferStatus::CvInReview, OfferStatus::MailInReview, OfferStatus::WachtOpKandidaat, OfferStatus::KlaarVoorVerzending))),
            'intake' => Tab::make('Intake')->modifyQueryUsing(fn (Builder $query) => $query->where('status', OfferStatus::IntakeGepland->value)),
            'geplaatst' => Tab::make('Geplaatst')->modifyQueryUsing(fn (Builder $query) => $query->where('status', OfferStatus::Geplaatst->value)),
            'afgewezen' => Tab::make('Afgewezen')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses(OfferStatus::Afgewezen, OfferStatus::GeenReactie, OfferStatus::Ingetrokken))),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Exporteer')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->export()),
            Action::make('import')
                ->label('Importeren')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn (): bool => Access::isStaff())
                ->modalDescription('Eerdere aanbiedingen uit een spreadsheet (CSV, puntkomma of komma). Kolommen: '.implode(';', array_keys(ImportOffers::COLUMNS)).'. Voorbeeld: docs/voorbeeld-aanbiedingen.csv. Dubbele aanbiedingen worden overgeslagen.')
                ->schema([
                    FileUpload::make('file')->label('CSV-bestand')->disk('local')->directory('offers/uploads')->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->maxSize(5120)->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(ImportOffers::class)->handle(Storage::disk('local')->path((string) $data['file']), Access::user() ?? abort(403));
                    } catch (RuntimeException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title($result['imported'].' aanbiedingen geïmporteerd')
                        ->body($result['skipped'] === [] ? null : implode('
', array_slice($result['skipped'], 0, 10)))
                        ->persistent()->send();
                }),
            Action::make('manual')
                ->label('Handmatig registreren')
                ->icon(Heroicon::OutlinedPlus)
                ->url(HandmatigRegistreren::getUrl())
                ->visible(fn (): bool => Access::isStaff()),
        ];
    }

    /**
     * Gefilterde export naar CSV (FR-AR9); opent in Excel (puntkomma, UTF-8 met BOM).
     */
    private function export(): StreamedResponse
    {
        /** @var Builder<Offer> $query */
        $query = $this->getFilteredSortedTableQuery() ?? OfferResource::getEloquentQuery();
        $offers = $query->get();

        return response()->streamDownload(function () use ($offers): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Aangeboden op', 'Kandidaat', 'Opdracht', 'Referentie', 'Eindklant', 'Via', 'Contactpersoon', 'Kanaal', 'Tarief', 'Max. tarief', 'Aangeboden door', 'Reactie verwacht', 'Indicator', 'Status', 'Externe referentie'], ';');

            foreach ($offers as $offer) {
                fputcsv($out, [
                    $offer->sent_at?->setTimezone(ResponseTerm::TIMEZONE)->format('d-m-Y H:i'),
                    $offer->candidate->name,
                    $offer->assignment->title,
                    $offer->assignment->reference,
                    $offer->assignment->client_name,
                    $offer->viaName(),
                    $offer->contact->name ?? $offer->contact?->email,
                    $offer->channel->getLabel(),
                    $offer->offered_rate !== null ? number_format((float) $offer->offered_rate, 2, ',', '') : null,
                    $offer->assignment->max_rate !== null ? number_format((float) $offer->assignment->max_rate, 2, ',', '') : null,
                    $offer->sentBy?->name,
                    $offer->expected_response_at?->setTimezone(ResponseTerm::TIMEZONE)->format('d-m-Y H:i'),
                    ResponseTerm::indicator($offer)['label'],
                    $offer->status->getLabel(),
                    $offer->external_reference,
                ], ';');
            }

            fclose($out);
        }, 'aanbiedingsregister-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
