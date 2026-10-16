@php
    $euro = fn (float $amount): string => '€ '.number_format($amount, 2, ',', '.');
    $num = fn (?float $value): string => $value === null ? '—' : str_replace('.', ',', (string) $value);
    $palette = ['#431858', '#8a5000', '#1d4f91', '#17623f', '#b0132f', '#6b5f74', '#c9a3dc'];
    $funnelMax = max(1, max(array_column($funnel, 'count')));
@endphp

<x-filament-panels::page>
    <div class="pz-match">
        <div style="max-width:260px">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="days">
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <div class="pz-tiles pz-match__block">
            <div class="pz-tile">
                <div class="pz-tile__label">Publicatie → aanbieding</div>
                <div class="pz-tile__value">{{ $leadTimes['publication_to_offer_hours'] === null ? '—' : $num($leadTimes['publication_to_offer_hours']).' u' }}</div>
                <div class="pz-tile__sub">gemiddeld, {{ $leadTimes['samples'] }} aanbiedingen</div>
            </div>
            <div class="pz-tile">
                <div class="pz-tile__label">Reactietijd</div>
                <div class="pz-tile__value">{{ $leadTimes['response_workdays'] === null ? '—' : $num($leadTimes['response_workdays']).' wd' }}</div>
                <div class="pz-tile__sub">werkdagen tot een reactie</div>
            </div>
            <div class="pz-tile">
                <div class="pz-tile__label">Aanbieding → intake</div>
                <div class="pz-tile__value">{{ $leadTimes['sent_to_intake_days'] === null ? '—' : $num($leadTimes['sent_to_intake_days']).' d' }}</div>
                <div class="pz-tile__sub">gemiddeld</div>
            </div>
            <div class="pz-tile">
                <div class="pz-tile__label">AI-kosten (max. 30 dagen)</div>
                <div class="pz-tile__value">{{ $euro($aiCosts['total']) }}</div>
                <div class="pz-tile__sub">geschat op basis van de ingestelde prijzen</div>
            </div>
        </div>

        <div class="pz-grid-2 pz-match__block">
            <div class="pz-card">
                <div class="pz-card__head"><h3 class="pz-card__title">Funnel</h3></div>
                @foreach ($funnel as $step)
                    <div class="pz-scorebar">
                        <div class="pz-scorebar__label"><span>{{ $step['label'] }}</span><span>{{ $step['count'] }}</span></div>
                        <div class="pz-bar"><div class="pz-bar__fill" style="width: {{ (int) round($step['count'] / $funnelMax * 100) }}%"></div></div>
                    </div>
                @endforeach
            </div>

            <div class="pz-card">
                <div class="pz-card__head"><h3 class="pz-card__title">Matches per kandidaat</h3></div>
                <div class="pz-matrix-wrap">
                    <table class="pz-matrix">
                        <thead><tr><th>Kandidaat</th><th>Sterk</th><th>Mogelijk</th><th>Zwak</th><th>Afgevallen</th><th>👍</th><th>👎</th><th>Aangeboden</th></tr></thead>
                        <tbody>
                            @forelse ($perCandidate as $row)
                                <tr><td>{{ $row['candidate'] }}</td><td>{{ $row['sterk'] }}</td><td>{{ $row['mogelijk'] }}</td><td>{{ $row['zwak'] }}</td><td>{{ $row['afgevallen'] }}</td><td>{{ $row['up'] }}</td><td>{{ $row['down'] }}</td><td>{{ $row['offered'] }}</td></tr>
                            @empty
                                <tr><td colspan="8" class="pz-empty">Nog geen matches in deze periode.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="pz-card pz-match__block">
            <div class="pz-card__head"><h3 class="pz-card__title">Nieuwe opdrachten per bron per week</h3></div>
            @if ($perSource['sources'] === [])
                <p class="pz-empty">Nog geen opdrachten in deze periode.</p>
            @else
                <div class="pz-weekchart" role="img" aria-label="Nieuwe opdrachten per bron per week">
                    @foreach ($perSource['weeks'] as $week)
                        <div class="pz-weekchart__col" title="{{ $week }}">
                            <div class="pz-weekchart__stack">
                                @foreach (array_values($perSource['sources']) as $index => $counts)
                                    @if ($counts[$week] > 0)
                                        <div style="height: {{ round($counts[$week] / max(1, $perSource['max']) * 100, 1) }}%; background: {{ $palette[$index % count($palette)] }}" title="{{ array_keys($perSource['sources'])[$index] }}: {{ $counts[$week] }}"></div>
                                    @endif
                                @endforeach
                            </div>
                            <span>{{ substr($week, -3) }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="pz-legend">
                    @foreach (array_keys($perSource['sources']) as $index => $name)
                        <span><i style="background: {{ $palette[$index % count($palette)] }}"></i>{{ $name }} ({{ array_sum($perSource['sources'][$name]) }})</span>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="pz-grid-2 pz-match__block">
            <div class="pz-card">
                <div class="pz-card__head"><h3 class="pz-card__title">AI-kosten per dag</h3></div>
                <div class="pz-weekchart pz-weekchart--small" role="img" aria-label="AI-kosten per dag">
                    @foreach ($aiCosts['days'] as $day => $cost)
                        <div class="pz-weekchart__col" title="{{ \Carbon\Carbon::parse($day)->format('d-m') }}: {{ $euro($cost) }}">
                            <div class="pz-weekchart__stack"><div style="height: {{ $aiCosts['max_day'] > 0 ? round($cost / $aiCosts['max_day'] * 100, 1) : 0 }}%; background: #431858"></div></div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="pz-card">
                <div class="pz-card__head"><h3 class="pz-card__title">AI per agent</h3></div>
                <table class="pz-matrix">
                    <thead><tr><th>Agent</th><th>Taken</th><th>Mislukt</th><th>Tokens</th><th>Kosten</th></tr></thead>
                    <tbody>
                        @forelse ($aiCosts['agents'] as $agent)
                            <tr><td>{{ $agent['agent'] }}</td><td>{{ $agent['runs'] }}</td><td>{{ $agent['failed'] }}</td><td>{{ number_format($agent['tokens'], 0, ',', '.') }}</td><td>{{ $euro($agent['cost']) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="pz-empty">Nog geen AI-taken.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pz-card pz-match__block">
            <div class="pz-card__head">
                <h3 class="pz-card__title">Gemiste kansen</h3>
                <span class="pz-tile__sub">aangeboden, terwijl de app de match laag scoorde</span>
            </div>
            @forelse ($missed as $row)
                <div class="pz-person">
                    <span class="pz-chip pz-chip--warning">{{ $row['score'] ?? '—' }}</span>
                    <div>
                        <a class="pz-person__name" href="{{ $offerUrl($row['offer']) }}">{{ $row['offer']->assignment->title }}</a>
                        <div class="pz-person__meta">{{ $row['offer']->candidate->name }} · via {{ $row['offer']->viaName() }} · {{ $row['label'] }} · {{ $row['offer']->status->getLabel() }}</div>
                    </div>
                </div>
            @empty
                <p class="pz-empty">Geen: alle aanbiedingen in deze periode hadden een sterke of mogelijke match.</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
