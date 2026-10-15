<?php

namespace App\Filament\Resources\Candidates\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Gestructureerde editor voor het master-profiel (Bijlage D). Alle velden staan onder `data.`.
 */
class MasterProfileEditor
{
    /**
     * @param  list<Tab>  $extraTabs  extra tabbladen, bijv. "Aansluiting op de opdracht" bij een getailord CV
     * @return list<Tabs>
     */
    public static function components(array $extraTabs = []): array
    {
        $period = '/^\d{4}(-\d{2})?$/';

        return [
            Tabs::make('Profiel')->columnSpanFull()->tabs([
                Tab::make('Kop en profiel')->schema([
                    Grid::make(2)->schema([
                        TextInput::make('data.naam')->label('Naam')->required(),
                        TextInput::make('data.cv_titel')->label('CV-titel'),
                        TextInput::make('data.tagline')->label('Tagline')->columnSpanFull(),
                        Textarea::make('data.profieltekst')->label('Professioneel profiel')->rows(6)->required()->columnSpanFull(),
                    ]),
                ]),
                Tab::make('Kernexpertise')->schema([
                    Repeater::make('data.kernexpertise')->hiddenLabel()->addActionLabel('Blok toevoegen')
                        ->itemLabel(fn (array $state): ?string => $state['kop'] ?? null)->collapsible()
                        ->schema([
                            TextInput::make('kop')->label('Kop')->required(),
                            Repeater::make('items')->label('Items')->columns(3)->addActionLabel('Item toevoegen')->schema([
                                TextInput::make('naam')->label('Naam')->required(),
                                Select::make('niveau')->label('Niveau')->options(['basis' => 'Basis', 'gevorderd' => 'Gevorderd', 'expert' => 'Expert'])->placeholder('—'),
                                TextInput::make('sinds')->label('Sinds')->regex('/^\d{4}$/')->placeholder('JJJJ'),
                            ]),
                        ]),
                ]),
                Tab::make('Werkervaring')->schema([
                    Repeater::make('data.werkervaring')->hiddenLabel()->addActionLabel('Werkervaring toevoegen')
                        ->itemLabel(fn (array $state): string => trim(($state['van'] ?? '').' – '.($state['tot'] ?? '').' · '.($state['functie'] ?? '').' · '.($state['organisatie'] ?? ''), ' ·–'))
                        ->collapsible()->collapsed()
                        ->schema([
                            Grid::make(4)->schema([
                                TextInput::make('id')->label('ID')->required(),
                                TextInput::make('van')->label('Van')->required()->regex($period)->placeholder('JJJJ-MM'),
                                TextInput::make('tot')->label('Tot')->required()->regex('/^(\d{4}(-\d{2})?|heden)$/')->placeholder('JJJJ-MM of heden'),
                                TextInput::make('via')->label('Via'),
                                TextInput::make('functie')->label('Functie')->required()->columnSpan(2),
                                TextInput::make('organisatie')->label('Organisatie')->required()->columnSpan(2),
                                TextInput::make('subtitel')->label('Subtitel')->columnSpanFull(),
                            ]),
                            Repeater::make('bullets')->label('Bullets')->simple(Textarea::make('tekst')->rows(2)->required())->addActionLabel('Bullet toevoegen'),
                            TagsInput::make('technologieen')->label('Technologieën'),
                        ]),
                ]),
                Tab::make('Projecten')->schema([
                    Repeater::make('data.projecten')->hiddenLabel()->addActionLabel('Project toevoegen')
                        ->itemLabel(fn (array $state): ?string => $state['naam'] ?? null)->collapsible()->collapsed()
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('id')->label('ID')->required(),
                                TextInput::make('naam')->label('Naam')->required(),
                                TextInput::make('periode')->label('Periode'),
                                TextInput::make('subtitel')->label('Subtitel')->columnSpanFull(),
                            ]),
                            Textarea::make('omschrijving')->label('Omschrijving')->rows(3),
                            TagsInput::make('technologieen')->label('Technologieën'),
                        ]),
                ]),
                Tab::make('Opleidingen en certificaten')->schema([
                    Repeater::make('data.opleidingen')->label('Opleidingen')->columns(4)->addActionLabel('Opleiding toevoegen')->schema([
                        TextInput::make('periode')->label('Periode')->required(),
                        TextInput::make('naam')->label('Naam')->required(),
                        TextInput::make('instelling')->label('Instelling'),
                        Select::make('status')->label('Status')->required()->options(['behaald' => 'Behaald', 'gevolgd' => 'Gevolgd', 'lopend' => 'Lopend']),
                    ]),
                    Repeater::make('data.certificaten')->label('Certificaten')->columns(2)->addActionLabel('Certificaat toevoegen')->schema([
                        TextInput::make('jaar')->label('Jaar')->required(),
                        TextInput::make('naam')->label('Naam')->required(),
                    ]),
                ]),
                Tab::make('Overig')->schema([
                    Repeater::make('data.eerdere_ervaring')->label('Eerdere ervaring en stages')->columns(4)->addActionLabel('Toevoegen')->schema([
                        TextInput::make('periode')->label('Periode')->required(),
                        TextInput::make('functie')->label('Functie')->required(),
                        TextInput::make('organisatie')->label('Organisatie')->required(),
                        TextInput::make('omschrijving')->label('Omschrijving'),
                    ]),
                    Repeater::make('data.talen')->label('Talen')->columns(2)->addActionLabel('Taal toevoegen')->schema([
                        TextInput::make('taal')->label('Taal')->required(),
                        TextInput::make('niveau')->label('Niveau')->required(),
                    ]),
                    TagsInput::make('data.overige_vaardigheden')->label('Overige vaardigheden'),
                    Repeater::make('data.aanvullende_secties')->label('Aanvullende secties (bijv. AI-augmented engineering)')->addActionLabel('Sectie toevoegen')
                        ->itemLabel(fn (array $state): ?string => $state['kop'] ?? null)->collapsible()
                        ->schema([
                            TextInput::make('kop')->label('Kop')->required(),
                            Repeater::make('items')->label('Items')->simple(TextInput::make('tekst')->required())->addActionLabel('Item toevoegen'),
                        ]),
                    Grid::make(3)->schema([
                        TextInput::make('data.sectiekoppen.opleidingen')->label('Kop opleidingen')->placeholder('Opleidingen & certificaten'),
                        TextInput::make('data.sectiekoppen.eerdere_ervaring')->label('Kop eerdere ervaring')->placeholder('Eerdere werkervaring'),
                        TextInput::make('data.sectiekoppen.talen')->label('Kop talen')->placeholder('Talen & aanvullende vaardigheden'),
                    ]),
                    TextInput::make('data.voetregel')->label('Slotregel onderaan het CV'),
                ]),
                ...$extraTabs,
            ]),
        ];
    }
}
