<?php

namespace App\Filament\Resources\Assignments\Schemas;

use App\Domain\Assignments\Models\Assignment;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Opdracht-detail (§15.5, FR-O7).
 */
class AssignmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $locked = fn (string $field): \Closure => fn (Assignment $record): ?string => $record->isFieldLocked($field) ? 'handmatig gecorrigeerd' : null;

        return $schema->components([
            Tabs::make('Opdracht')->columnSpanFull()->persistTabInQueryString()->tabs([
                Tab::make('Samenvatting')->icon('heroicon-o-document-text')->schema([
                    ViewEntry::make('offers')->hiddenLabel()->columnSpanFull()->view('filament.assignments.offers'),
                    TextEntry::make('warnings')->hiddenLabel()->columnSpanFull()
                        ->state(fn (Assignment $record): array => array_values(array_filter([
                            ! $record->is_relevant ? 'Niet relevant: '.$record->relevance_reason : null,
                            $record->is_incomplete ? 'Onvolledig: de volledige opdrachttekst was niet beschikbaar; controleer de velden.' : null,
                            $record->possible_duplicate_of_id !== null ? 'Mogelijk duplicaat van "'.$record->possibleDuplicateOf?->title.'" (#'.$record->possible_duplicate_of_id.').' : null,
                            $record->ignore_reason !== null ? 'Genegeerd: '.$record->ignore_reason : null,
                        ])))
                        ->badge()->color('warning')->visible(fn (Assignment $record): bool => ! $record->is_relevant || $record->is_incomplete || $record->possible_duplicate_of_id !== null || $record->ignore_reason !== null),
                    TextEntry::make('summary')->label('Samenvatting')->placeholder('—')->columnSpanFull(),
                    Grid::make(4)->schema([
                        TextEntry::make('client_name')->label('Eindklant')->placeholder('—')->helperText($locked('client_name')),
                        TextEntry::make('intermediary_name')->label('Intermediair')->placeholder('—')->helperText($locked('intermediary_name')),
                        TextEntry::make('reference')->label('Referentie')->placeholder('—')->copyable(),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('location')->label('Locatie')->state(fn (Assignment $record): ?string => collect([$record->city, $record->province])->filter()->implode(', ') ?: $record->location_text)->placeholder('—')->helperText($locked('city')),
                        TextEntry::make('work_mode')->label('Werkvorm')->badge()->color('gray')
                            ->helperText(fn (Assignment $record): ?string => $record->onsite_days_per_week !== null ? $record->onsite_days_per_week.' dag(en) op locatie' : null),
                        TextEntry::make('hours')->label('Uren per week')->state(fn (Assignment $record): ?string => $record->hours_min === null ? null : ($record->hours_min === $record->hours_max || $record->hours_max === null ? (string) $record->hours_min : $record->hours_min.'–'.$record->hours_max))->placeholder('—')->helperText($locked('hours_min')),
                        TextEntry::make('max_rate')->label('Max. tarief')->money('EUR', locale: 'nl')->placeholder('Onbekend')
                            ->helperText(fn (Assignment $record): ?string => trim(($record->rate_includes_margin ? 'incl. marge · ' : '').($record->rate_text ?? '')) ?: null),
                        TextEntry::make('start')->label('Start')->state(fn (Assignment $record): ?string => $record->start_asap ? 'Per direct' : $record->start_date?->format('d-m-Y'))->placeholder('—'),
                        TextEntry::make('duration')->label('Duur')->state(fn (Assignment $record): ?string => collect([
                            $record->duration_months !== null ? $record->duration_months.' maanden' : null,
                            $record->end_date ? 'tot '.$record->end_date->format('d-m-Y') : null,
                        ])->filter()->implode(', ') ?: null)->placeholder('—')->helperText(fn (Assignment $record): ?string => $record->extension_option),
                        TextEntry::make('contract_type')->label('Contractvorm')->badge()->color('gray')
                            ->helperText(fn (Assignment $record): string => match ($record->zzp_allowed) {
                                true => 'Zzp toegestaan',
                                false => 'Zzp niet toegestaan',
                                default => 'Zzp: onbekend',
                            }),
                        TextEntry::make('sector')->label('Sector')->badge()->color('gray'),
                        TextEntry::make('deadline_at')->label('Deadline')->dateTime('d-m-Y H:i')->placeholder('—')
                            ->helperText(fn (Assignment $record): ?string => $record->deadline_at?->isFuture() ? 'nog '.$record->deadline_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE) : null),
                        TextEntry::make('seniority')->label('Senioriteit')->formatStateUsing(fn (string $state): string => ucfirst($state)),
                        TextEntry::make('language')->label('Taal')->formatStateUsing(fn (string $state): string => $state === 'en' ? 'Engels' : 'Nederlands'),
                        TextEntry::make('first_seen_at')->label('Eerst gezien')->dateTime('d-m-Y H:i'),
                    ]),
                    TextEntry::make('technologies')->label('Technologieën')->badge()->color('primary')->placeholder('—')->columnSpanFull(),
                    Grid::make(2)->schema([
                        TextEntry::make('screening_text')->label('Screening')->state(fn (Assignment $record): ?string => collect([
                            ($record->screening['vog'] ?? null) === true ? 'VOG vereist' : null,
                            $record->screening['nationaliteit'] ?? null,
                            $record->screening['veiligheidsonderzoek'] ?? null,
                        ])->filter()->implode(' · ') ?: null)->placeholder('Niet genoemd'),
                        TextEntry::make('submission_text')->label('Indienen')->state(fn (Assignment $record): ?string => collect([
                            ($record->submission['alleen_via_platform'] ?? null) === true ? 'Alleen via platform' : null,
                            isset($record->submission['max_tekens_per_eis']) ? 'max. '.$record->submission['max_tekens_per_eis'].' tekens per eis' : null,
                            $record->submission['overig'] ?? null,
                        ])->filter()->implode(' · ') ?: null)->placeholder('Niet genoemd'),
                    ]),
                    TextEntry::make('extraction')->label('Extractie')->color('gray')
                        ->state(fn (Assignment $record): string => collect([$record->extraction_model, $record->extraction_prompt_version, $record->locked_fields ? 'vergrendeld: '.implode(', ', $record->locked_fields) : null])->filter()->implode(' · ') ?: 'Geen AI-extractie (voorfilter)'),
                ]),
                Tab::make('Eisen en wensen')->icon('heroicon-o-list-bullet')
                    ->badge(fn (Assignment $record): int => count($record->requirements ?? []) + count($record->wishes ?? []))
                    ->schema([
                        ViewEntry::make('requirements')->hiddenLabel()->view('filament.assignments.requirements'),
                    ]),
                Tab::make('Matches')->icon('heroicon-o-sparkles')
                    ->badge(fn (Assignment $record): ?string => ($best = $record->matches()->max('score')) !== null ? (string) $best : null)
                    ->schema([ViewEntry::make('matches')->hiddenLabel()->view('filament.assignments.matches')]),
                Tab::make('Originele tekst')->icon('heroicon-o-document')->schema([
                    TextEntry::make('description_text')->hiddenLabel()->placeholder('Geen tekst')
                        ->extraAttributes(['style' => 'white-space: pre-line; max-width: 80ch']),
                ]),
                Tab::make('Bronnen')->icon('heroicon-o-link')
                    ->badge(fn (Assignment $record): int => $record->sources()->count())
                    ->schema([ViewEntry::make('sources')->hiddenLabel()->view('filament.assignments.sources')]),
                Tab::make('Reisafstand')->icon('heroicon-o-map-pin')->schema([
                    ViewEntry::make('distances')->hiddenLabel()->view('filament.assignments.distances'),
                ]),
                Tab::make('Historie')->icon('heroicon-o-clock')->schema([
                    ViewEntry::make('history')->hiddenLabel()->view('filament.assignments.history'),
                ]),
            ]),
        ]);
    }
}
