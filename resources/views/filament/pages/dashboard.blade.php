@php
    $euro = fn (float $amount): string => '€ '.number_format($amount, 2, ',', '.');
    $lastTest = $aiProfile?->last_test_result;
@endphp

<x-filament-panels::page>
    <div class="pz-dash">
        <div class="pz-hello">
            <h2>{{ $greeting }}, {{ $firstName }}</h2>
            <p>{{ ucfirst($today) }} · {{ $newSinceYesterday }} {{ $newSinceYesterday === 1 ? 'nieuwe opdracht' : 'nieuwe opdrachten' }} sinds gisteren</p>
        </div>

        <div class="pz-tiles">
            <div class="pz-tile">
                <div class="pz-tile__label">Sterke matches vandaag</div>
                <div @class(['pz-tile__value', 'pz-tile__value--muted' => $strongToday === 0])>{{ $strongToday }}</div>
                <div class="pz-tile__sub">{{ $strongOpen->count() }} nog niet ingedeeld</div>
            </div>
            <div class="pz-tile">
                <div class="pz-tile__label">Deadline binnen 24 uur</div>
                <div @class(['pz-tile__value', 'pz-tile__value--muted' => $shortlistSoon->isEmpty()])>{{ $shortlistSoon->count() }}</div>
                <div class="pz-tile__sub">Op de shortlist · {{ $deadlineSoon->count() }} open opdrachten in totaal</div>
            </div>
            <div class="pz-tile">
                <div class="pz-tile__label">Aanbiedingen deze week</div>
                <div class="pz-tile__value pz-tile__value--muted">—</div>
                <div class="pz-tile__sub">Volgt in fase 4</div>
            </div>
            <div class="pz-tile">
                <div class="pz-tile__label">AI-kosten vandaag</div>
                <div class="pz-tile__value">{{ $euro($spentToday) }}</div>
                <div class="pz-tile__sub">van {{ $euro($budget) }} dagbudget</div>
            </div>
        </div>

        <div class="pz-grid-2">
            <div class="pz-card">
                <div class="pz-card__head">
                    <h3 class="pz-card__title">Actie nodig</h3>
                    <span class="pz-tile__sub">gesorteerd op urgentie</span>
                </div>
                @if ($isStaff && ! $aiProfile)
                    <div class="pz-person">
                        <span class="pz-chip pz-chip--warning">AI-agent</span>
                        <div>
                            <div class="pz-person__name">Activeer het AI-agentprofiel</div>
                            <div class="pz-person__meta">Bevestig de verwerkersovereenkomst en test de verbinding.</div>
                        </div>
                        <div class="pz-person__aside">
                            <x-filament::button tag="a" size="sm" color="gray" :href="\App\Filament\Clusters\Settings\Pages\AiAgentSettings::getUrl()">Instellen</x-filament::button>
                        </div>
                    </div>
                @endif
                @foreach ($candidates->filter(fn ($c) => $c->activeProfile === null) as $candidate)
                    @if ($isStaff)
                        <div class="pz-person">
                            <span class="pz-chip pz-chip--info">Master-CV</span>
                            <div>
                                <div class="pz-person__name">{{ $candidate->name }}: master-profiel ontbreekt</div>
                                <div class="pz-person__meta">Importeer het Word-CV en keur het profiel goed.</div>
                            </div>
                            <div class="pz-person__aside">
                                <x-filament::button tag="a" size="sm" color="gray" :href="$candidateUrl($candidate)">Openen</x-filament::button>
                            </div>
                        </div>
                    @endif
                @endforeach
                @foreach ($shortlistSoon as $match)
                    <div class="pz-person">
                        <span class="pz-chip pz-chip--danger">Deadline {{ $match->assignment->deadline_at->isToday() ? 'vandaag' : 'morgen' }} {{ $match->assignment->deadline_at->format('H:i') }}</span>
                        <div>
                            <div class="pz-person__name">{{ $match->assignment->title }}</div>
                            <div class="pz-person__meta">Shortlist · {{ $match->candidate->name }} · nog niet aangeboden</div>
                        </div>
                        <div class="pz-person__aside"><x-filament::button tag="a" size="sm" color="gray" :href="$matchUrl($match)">Bekijken</x-filament::button></div>
                    </div>
                @endforeach
                @foreach ($strongOpen as $match)
                    <div class="pz-person">
                        <span class="pz-chip pz-chip--success">Sterke match {{ $match->score }}</span>
                        <div>
                            <div class="pz-person__name">{{ $match->assignment->title }}</div>
                            <div class="pz-person__meta">{{ collect([$isStaff ? $match->candidate->name : null, $match->assignment->client_name, $match->assignment->deadline_at ? 'deadline '.$match->assignment->deadline_at->format('d-m H:i') : null])->filter()->implode(' · ') }}</div>
                        </div>
                        <div class="pz-person__aside"><x-filament::button tag="a" size="sm" color="gray" :href="$matchUrl($match)">Bekijken</x-filament::button></div>
                    </div>
                @endforeach
                @if ($isStaff)
                @foreach ($deadlineSoon as $assignment)
                    <div class="pz-person">
                        <span class="pz-chip pz-chip--warning">Deadline {{ $assignment->deadline_at->isToday() ? 'vandaag' : 'morgen' }} {{ $assignment->deadline_at->format('H:i') }}</span>
                        <div>
                            <div class="pz-person__name">{{ $assignment->title }}</div>
                            <div class="pz-person__meta">{{ collect([$assignment->client_name, $assignment->city])->filter()->implode(' · ') }}</div>
                        </div>
                        <div class="pz-person__aside"><x-filament::button tag="a" size="sm" color="gray" :href="$assignmentUrl($assignment)">Bekijken</x-filament::button></div>
                    </div>
                @endforeach
                @foreach ($latestAssignments->diff($deadlineSoon) as $assignment)
                    <div class="pz-person">
                        <span class="pz-chip pz-chip--info">Nieuw</span>
                        <div>
                            <div class="pz-person__name">{{ $assignment->title }}</div>
                            <div class="pz-person__meta">{{ collect([$assignment->client_name, $assignment->city, $assignment->deadline_at ? 'deadline '.$assignment->deadline_at->format('d-m H:i') : null])->filter()->implode(' · ') }}</div>
                        </div>
                        <div class="pz-person__aside"><x-filament::button tag="a" size="sm" color="gray" :href="$assignmentUrl($assignment)">Bekijken</x-filament::button></div>
                    </div>
                @endforeach
                @endif
                <p class="pz-empty">Reacties op aanbiedingen verschijnen hier vanaf fase 4.</p>
            </div>

            <div class="pz-card">
                <div class="pz-card__head">
                    <h3 class="pz-card__title">Status</h3>
                </div>
                <div class="pz-person">
                    <div>
                        <div class="pz-person__name">AI-verbinding</div>
                        <div class="pz-person__meta">
                            @if ($aiProfile?->last_test_at)
                                Laatst getest {{ $aiProfile->last_test_at->format('d-m-Y H:i') }}
                            @else
                                Nog niet getest
                            @endif
                        </div>
                    </div>
                    <div class="pz-person__aside">
                        @if (! $aiProfile)
                            <span class="pz-chip pz-chip--warning">Niet actief</span>
                        @elseif ($lastTest === null)
                            <span class="pz-chip">Onbekend</span>
                        @elseif ($lastTest['passed'] ?? false)
                            <span class="pz-chip pz-chip--success">✓ Geslaagd</span>
                        @else
                            <span class="pz-chip pz-chip--danger">✕ Mislukt</span>
                        @endif
                    </div>
                </div>
                @foreach ($sources as $source)
                    <div class="pz-person">
                        <div>
                            <div class="pz-person__name">{{ $source->name }}</div>
                            <div class="pz-person__meta">{{ $source->last_run_at ? 'Laatste run '.$source->last_run_at->format('d-m H:i') : 'Nog niet opgehaald' }}{{ $source->health_warning ? ' · '.$source->health_warning : '' }}</div>
                        </div>
                        <div class="pz-person__aside">
                            @if (! $source->enabled)
                                <span class="pz-chip">Uit</span>
                            @elseif ($source->health_warning || $source->consecutive_failures > 0)
                                <span class="pz-chip pz-chip--danger">Fout</span>
                            @else
                                <span class="pz-chip pz-chip--success">Actief</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="pz-card">
            <div class="pz-card__head">
                <h3 class="pz-card__title">Kandidaten</h3>
                @if ($isStaff)
                    <a class="pz-card__link" href="{{ \App\Filament\Resources\Candidates\CandidateResource::getUrl() }}">Alle kandidaten</a>
                @endif
            </div>
            @forelse ($candidates as $candidate)
                <div class="pz-person">
                    <span class="pz-avatar">{{ $candidate->initials() }}</span>
                    <div>
                        <a class="pz-person__name" href="{{ $candidateUrl($candidate) }}">{{ $candidate->name }}</a>
                        <div class="pz-person__meta">
                            {{ $candidate->job_title }} · {{ $euro((float) $candidate->hourly_rate) }} · {{ $candidate->hours_per_week }} uur ·
                            {{ $candidate->isAvailableImmediately() ? 'per direct' : 'vanaf '.$candidate->available_from?->format('d-m-Y') }}
                            @foreach ($candidate->absences->where('ends_on', '>=', now()->startOfDay()) as $absence)
                                · {{ $absence->type->getLabel() }} {{ $absence->starts_on->format('d-m') }} t/m {{ $absence->ends_on->format('d-m-Y') }}
                            @endforeach
                        </div>
                    </div>
                    <div class="pz-person__aside">
                        <span @class(['pz-chip', 'pz-chip--success' => $candidate->status->value === 'beschikbaar', 'pz-chip--warning' => $candidate->status->value === 'in_procedure'])>{{ $candidate->status->getLabel() }}</span>
                        <div class="pz-person__meta">
                            {{ $candidate->activeProfile ? 'Master-profiel v'.$candidate->activeProfile->version : 'Geen master-profiel' }}
                        </div>
                    </div>
                </div>
            @empty
                <p class="pz-empty">Nog geen kandidaten.</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
