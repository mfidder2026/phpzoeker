@php
    /** @var \App\Domain\Assignments\Models\Assignment $record */
    $record = $getRecord();
    $categories = ['techniek' => 'Techniek', 'ervaring' => 'Ervaring', 'opleiding' => 'Opleiding', 'certificaat' => 'Certificaat', 'screening' => 'Screening', 'taal' => 'Taal', 'competentie' => 'Competentie', 'overig' => 'Overig'];
@endphp

<div class="pz-cv">
    <h4>Eisen ({{ count($record->requirements ?? []) }})</h4>
    @forelse ($record->requirements ?? [] as $requirement)
        <div class="pz-person">
            <span @class(['pz-chip', 'pz-chip--danger' => $requirement['knock_out'] ?? false])>{{ $requirement['id'] ?? '' }}{{ ($requirement['knock_out'] ?? false) ? ' · knock-out' : '' }}</span>
            <div>
                <div>{{ $requirement['tekst'] ?? '' }}</div>
                <div class="pz-person__meta">
                    {{ $categories[$requirement['categorie'] ?? 'overig'] ?? ucfirst((string) ($requirement['categorie'] ?? '')) }}
                    @if (! empty($requirement['min_jaren'])) · minimaal {{ $requirement['min_jaren'] }} jaar @endif
                    @if (! empty($requirement['technologie'])) · {{ $requirement['technologie'] }} @endif
                </div>
            </div>
        </div>
    @empty
        <p class="pz-empty">Geen eisen gevonden.</p>
    @endforelse

    <h4>Wensen ({{ count($record->wishes ?? []) }})</h4>
    @forelse ($record->wishes ?? [] as $wish)
        <div class="pz-person">
            <span class="pz-chip pz-chip--info">{{ $wish['id'] ?? '' }}{{ isset($wish['gewicht']) ? ' · '.$wish['gewicht'] : '' }}</span>
            <div>
                <div>{{ $wish['tekst'] ?? '' }}</div>
                <div class="pz-person__meta">{{ $categories[$wish['categorie'] ?? 'overig'] ?? ucfirst((string) ($wish['categorie'] ?? '')) }}</div>
            </div>
        </div>
    @empty
        <p class="pz-empty">Geen wensen gevonden.</p>
    @endforelse
</div>
