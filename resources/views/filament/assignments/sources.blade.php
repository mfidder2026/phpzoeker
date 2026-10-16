@php
    /** @var \App\Domain\Assignments\Models\Assignment $record */
    $record = $getRecord();
    $sources = $record->sources()->with(['source', 'organization'])->orderBy('first_seen_at')->get();
@endphp

<div>
    @forelse ($sources as $link)
        <div class="pz-person">
            <span class="pz-chip">{{ $link->source->type->getLabel() }}</span>
            <div style="min-width:0">
                <div class="pz-person__name">{{ $link->source->name }}</div>
                <div class="pz-person__meta">
                    @if ($link->intermediary_name) Via {{ $link->intermediary_name }} · @endif
                    eerst gezien {{ $link->first_seen_at?->format('d-m-Y H:i') }} · laatst gezien {{ $link->last_seen_at?->format('d-m-Y H:i') }}
                </div>
                <div class="pz-person__meta">Contact: {{ $link->contact_name ?? 'onbekend' }} · {{ $link->contact_email ?? 'geen e-mailadres bekend' }}</div>
                @if ($link->url)
                    <a class="pz-card__link" href="{{ $link->url }}" target="_blank" rel="noopener noreferrer" style="word-break:break-all">{{ $link->url }}</a>
                @endif
            </div>
        </div>
    @empty
        <p class="pz-empty">Geen bronnen gekoppeld.</p>
    @endforelse
</div>
