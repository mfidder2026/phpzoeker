@php
    /** @var \App\Domain\Assignments\Models\Assignment $record */
    $record = $getRecord();
    $matches = $record->matches()->with(['candidate', 'assignment'])->orderByDesc('score')->get();
@endphp

<div class="pz-match-list">
    @forelse ($matches as $match)
        <div>
            <div class="pz-card__head">
                <h3 class="pz-card__title">{{ $match->candidate->name }}</h3>
                <a class="pz-card__link" href="{{ \App\Filament\Resources\Matches\MatchResource::getUrl('view', ['record' => $match]) }}">Match openen</a>
            </div>
            @include('filament.matches.match', ['match' => $match, 'compact' => true])
        </div>
    @empty
        <p class="pz-empty">
            Nog geen matches. Matching gebeurt automatisch voor relevante, open opdrachten en kandidaten met een goedgekeurd master-profiel.
        </p>
    @endforelse
</div>
