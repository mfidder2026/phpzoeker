@php
    /** @var array<string, mixed> $data */
@endphp

<div class="pz-cv">
    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <div>
            <div style="font-size:1.3rem;font-weight:700;color:var(--pz-primary)">{{ mb_strtoupper($data['naam'] ?? '') }}</div>
            <div style="font-weight:600">{{ $data['cv_titel'] ?? '' }}</div>
            <div class="pz-cv__muted">{{ $data['tagline'] ?? '' }}</div>
        </div>
        <div class="pz-cv__muted" style="text-align:right">
            Versie {{ $record->version }}<br>
            {{ $record->isApproved() ? 'Goedgekeurd '.$record->approved_at?->format('d-m-Y') : 'Concept' }}
        </div>
    </div>

    <h4>Professioneel profiel</h4>
    <p style="white-space:pre-line">{{ $data['profieltekst'] ?? '' }}</p>

    @if (! empty($data['kernexpertise']))
        <h4>Kernexpertise</h4>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.5rem 1.5rem">
            @foreach ($data['kernexpertise'] as $block)
                <div>
                    <strong>{{ $block['kop'] ?? '' }}</strong>
                    <div class="pz-cv__muted">
                        {{ collect($block['items'] ?? [])->map(fn ($item) => ($item['naam'] ?? '').(! empty($item['niveau']) ? ' ('.$item['niveau'].')' : '').(! empty($item['sinds']) ? ' sinds '.$item['sinds'] : ''))->implode(' · ') }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($data['werkervaring']))
        <h4>Werkervaring</h4>
        @foreach ($data['werkervaring'] as $entry)
            <div class="pz-cv__entry">
                <strong>{{ $entry['functie'] ?? '' }}</strong> — {{ $entry['organisatie'] ?? '' }}
                @if (! empty($entry['via'])) <span class="pz-cv__muted">(via {{ $entry['via'] }})</span> @endif
                <span class="pz-cv__muted">· {{ $entry['van'] ?? '' }} – {{ $entry['tot'] ?? '' }} · {{ $entry['id'] ?? '' }}</span>
                @if (! empty($entry['subtitel'])) <div class="pz-cv__muted">{{ $entry['subtitel'] }}</div> @endif
                <ul>
                    @foreach ($entry['bullets'] ?? [] as $bullet)
                        <li>{{ $bullet }}</li>
                    @endforeach
                </ul>
                @if (! empty($entry['technologieen']))
                    <div class="pz-cv__muted">Technologie: {{ implode(', ', $entry['technologieen']) }}</div>
                @endif
            </div>
        @endforeach
    @endif

    @if (! empty($data['projecten']))
        <h4>Geselecteerde projecten</h4>
        @foreach ($data['projecten'] as $project)
            <div class="pz-cv__entry">
                <strong>{{ $project['naam'] ?? '' }}</strong>
                @if (! empty($project['subtitel'])) <span class="pz-cv__muted">· {{ $project['subtitel'] }}</span> @endif
                @if (! empty($project['periode'])) <span class="pz-cv__muted">· {{ $project['periode'] }}</span> @endif
                <div>{{ $project['omschrijving'] ?? '' }}</div>
                @if (! empty($project['technologieen']))
                    <div class="pz-cv__muted">Technologie: {{ implode(', ', $project['technologieen']) }}</div>
                @endif
            </div>
        @endforeach
    @endif

    @foreach ($data['aanvullende_secties'] ?? [] as $section)
        <h4>{{ $section['kop'] ?? '' }}</h4>
        <ul>
            @foreach ($section['items'] ?? [] as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    @endforeach

    @if (! empty($data['opleidingen']) || ! empty($data['certificaten']))
        <h4>{{ $data['sectiekoppen']['opleidingen'] ?? 'Opleidingen en certificaten' }}</h4>
        <ul>
            @foreach ($data['opleidingen'] ?? [] as $education)
                <li>{{ $education['periode'] ?? '' }} — {{ $education['naam'] ?? '' }} @if (! empty($education['instelling'])) ({{ $education['instelling'] }}) @endif <span class="pz-cv__muted">· {{ $education['status'] ?? '' }}</span></li>
            @endforeach
            @foreach ($data['certificaten'] ?? [] as $certificate)
                <li>{{ $certificate['jaar'] ?? '' }} — {{ $certificate['naam'] ?? '' }}</li>
            @endforeach
        </ul>
    @endif

    @if (! empty($data['eerdere_ervaring']))
        <h4>Eerdere ervaring en stages</h4>
        <ul>
            @foreach ($data['eerdere_ervaring'] as $entry)
                <li>{{ $entry['periode'] ?? '' }} — {{ $entry['functie'] ?? '' }}, {{ $entry['organisatie'] ?? '' }}</li>
            @endforeach
        </ul>
    @endif

    @if (! empty($data['talen']) || ! empty($data['overige_vaardigheden']))
        <h4>Talen en vaardigheden</h4>
        <p>{{ collect($data['talen'] ?? [])->map(fn ($l) => ($l['taal'] ?? '').' ('.($l['niveau'] ?? '').')')->implode(' · ') }}</p>
        <p class="pz-cv__muted">{{ implode(' · ', $data['overige_vaardigheden'] ?? []) }}</p>
    @endif
</div>
