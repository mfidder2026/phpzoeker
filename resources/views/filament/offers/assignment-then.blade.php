@php
    /** @var \App\Domain\Offers\Models\Offer $record */
    $record = $getRecord();
    $data = $record->snapshot?->assignment_data;
    $changed = $data !== null && ($data['updated_at'] ?? null) !== $record->assignment->updated_at?->toIso8601String();
@endphp

<div class="pz-match">
    @if ($data === null)
        <p class="pz-empty">De opdracht wordt vastgelegd op het moment van verzending.</p>
    @else
        @if ($changed)
            <div class="pz-alert pz-alert--warning">De opdracht is sinds de aanbieding gewijzigd. Hieronder staat de versie van toen.</div>
        @endif
        <p><a class="pz-card__link" href="{{ \App\Filament\Resources\Assignments\AssignmentResource::getUrl('view', ['record' => $record->assignment_id]) }}">Naar de huidige versie van de opdracht</a></p>
        <div class="pz-card pz-match__block">
            <h3 class="pz-card__title">{{ $data['title'] }}{{ $data['reference'] ? ' ('.$data['reference'].')' : '' }}</h3>
            <p class="pz-person__meta">{{ collect([$data['client_name'] ?? null, $data['intermediary_name'] ?? null ? 'via '.$data['intermediary_name'] : null])->filter()->implode(' · ') }}</p>
            @if (filled($data['summary'] ?? null))
                <p style="margin-top:.5rem">{{ $data['summary'] }}</p>
            @endif
            <div class="pz-cv">
                <h4>Eisen</h4>
                <ul>@foreach ($data['fields']['requirements'] ?? [] as $requirement)<li>{{ $requirement['id'] ?? '' }} — {{ $requirement['tekst'] ?? '' }}{{ ($requirement['knock_out'] ?? false) ? ' (knock-out)' : '' }}</li>@endforeach</ul>
                <h4>Wensen</h4>
                <ul>@foreach ($data['fields']['wishes'] ?? [] as $wish)<li>{{ $wish['id'] ?? '' }} — {{ $wish['tekst'] ?? '' }}</li>@endforeach</ul>
            </div>
        </div>
        @if ($record->snapshot?->assessment)
            <div class="pz-card pz-match__block">
                <div class="pz-card__head"><h3 class="pz-card__title">Eisen-matrix toen (score {{ $record->snapshot->assessment['score'] ?? '—' }})</h3></div>
                <table class="pz-matrix">
                    <thead><tr><th></th><th>Eis of wens</th><th>Oordeel</th><th>Bewijs</th></tr></thead>
                    <tbody>
                        @foreach ([...($record->snapshot->assessment['eisen'] ?? []), ...($record->snapshot->assessment['wensen'] ?? [])] as $row)
                            <tr><td>{{ $row['id'] ?? '' }}</td><td>{{ $row['tekst'] ?? '' }}</td><td>{{ \App\Domain\Matching\Enums\Verdict::tryFrom((string) ($row['oordeel'] ?? ''))?->getLabel() ?? '—' }}</td><td>{{ $row['bewijs'] ?? '—' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <details class="pz-card pz-match__block">
            <summary>Originele tekst van toen</summary>
            <div style="white-space:pre-line;max-width:80ch;margin-top:.6rem">{{ $data['description_text'] ?? '' }}</div>
        </details>
    @endif
</div>
