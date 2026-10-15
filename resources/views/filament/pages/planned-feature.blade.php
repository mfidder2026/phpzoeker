<x-filament-panels::page>
    <div class="pz-planned">
        <span class="pz-chip pz-chip--info">Volgt in fase {{ $phase }}</span>
        <h2>Dit onderdeel is nog niet gebouwd</h2>
        <p>{{ $summary }}</p>
        @if (count($features))
            <ul>
                @foreach ($features as $feature)
                    <li>{{ $feature }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
