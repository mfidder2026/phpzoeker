@php
    /** @var \App\Domain\Assignments\Models\Assignment $record */
    $record = $getRecord();
    $rows = app(\App\Domain\Geo\CandidateDistances::class)->for($record);
@endphp

<div>
    <p class="pz-person__meta" style="margin-bottom:.5rem">
        Hemelsbreed × {{ str_replace('.', ',', (string) \App\Domain\Geo\Distance::ROAD_FACTOR) }} als benadering van de rijafstand (§9.3); reistijd bij gemiddeld {{ \App\Domain\Geo\Distance::AVERAGE_SPEED }} km/u.
        @if ($record->work_mode === \App\Domain\Assignments\Enums\WorkMode::Remote) Deze opdracht is remote. @endif
    </p>
    @forelse ($rows as $row)
        <div class="pz-person">
            <span class="pz-avatar">{{ $row['candidate']->initials() }}</span>
            <div>
                <div class="pz-person__name">{{ $row['candidate']->name }}</div>
                <div class="pz-person__meta">{{ $row['candidate']->city ?? 'Woonplaats onbekend' }}</div>
            </div>
            <div class="pz-person__aside">
                @if ($row['km'] === null)
                    <span class="pz-chip">Onbekend</span>
                @else
                    <span class="pz-chip">{{ number_format($row['km'], 0, ',', '.') }} km · ± {{ $row['minutes'] }} min</span>
                @endif
            </div>
        </div>
    @empty
        <p class="pz-empty">Geen actieve kandidaten.</p>
    @endforelse
</div>
