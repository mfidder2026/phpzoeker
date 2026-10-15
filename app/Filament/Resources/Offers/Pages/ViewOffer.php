<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Support\ResponseTerm;
use App\Filament\Resources\Offers\OfferActions;
use App\Filament\Resources\Offers\OfferResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Detailpagina van het register (§11.5): Overzicht, Zo zag de aanbieding eruit, Tijdlijn,
 * Opdracht (toen) en Notities.
 *
 * @property Offer $record
 */
class ViewOffer extends ViewRecord
{
    protected static string $resource = OfferResource::class;

    public function getTitle(): string
    {
        return $this->record->candidate->name.' — '.$this->record->assignment->title;
    }

    public function getSubheading(): string
    {
        return collect([
            $this->record->status->getLabel(),
            'via '.$this->record->viaName(),
            $this->record->sent_at ? 'aangeboden '.$this->record->sent_at->setTimezone(ResponseTerm::TIMEZONE)->format('d-m-Y H:i') : null,
            ResponseTerm::indicator($this->record)['state'] !== 'none' ? 'reactie: '.ResponseTerm::indicator($this->record)['label'] : null,
        ])->filter()->implode(' · ');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Aanbieding')->columnSpanFull()->persistTabInQueryString()->tabs([
                Tab::make('Overzicht')->icon('heroicon-o-identification')->schema([
                    ViewEntry::make('overview')->hiddenLabel()->view('filament.offers.overview'),
                ]),
                Tab::make('Zo zag de aanbieding eruit')->icon('heroicon-o-camera')->schema([
                    ViewEntry::make('snapshot')->hiddenLabel()->view('filament.offers.snapshot'),
                ]),
                Tab::make('Tijdlijn')->icon('heroicon-o-clock')
                    ->badge(fn (Offer $record): int => $record->activities()->count())
                    ->schema([ViewEntry::make('timeline')->hiddenLabel()->view('filament.offers.timeline')]),
                Tab::make('Opdracht (toen)')->icon('heroicon-o-document-text')->schema([
                    ViewEntry::make('assignment_then')->hiddenLabel()->view('filament.offers.assignment-then'),
                ]),
                Tab::make('Notities')->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->badge(fn (Offer $record): int => $record->activities()->where('type', 'note')->count())
                    ->schema([ViewEntry::make('notes')->hiddenLabel()->view('filament.offers.notes')]),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('wizard')->label('Verder met aanbieden')->icon(Heroicon::OutlinedArrowRight)
                ->url(fn (): string => OfferResource::getUrl('wizard', ['record' => $this->record]))
                ->visible(fn (): bool => $this->record->status->isPreparing()),
            OfferActions::followUp(),
            OfferActions::changeStatus(),
            OfferActions::addNote(),
            ActionGroup::make([
                OfferActions::recordResponse(),
                OfferActions::recordIntake(),
                OfferActions::adjustResponseTerm(),
                OfferActions::withdraw(),
            ]),
        ];
    }
}
