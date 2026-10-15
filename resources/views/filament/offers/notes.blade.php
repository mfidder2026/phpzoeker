@php
    /** @var \App\Domain\Offers\Models\Offer $record */
    $record = $getRecord();
    $tz = \App\Domain\Offers\Support\ResponseTerm::TIMEZONE;
    $notes = $record->activities()->with('user')->where('type', \App\Domain\Offers\Enums\OfferActivityType::Note->value)->get();
@endphp

<div>
    <p class="pz-person__meta">Notities worden alleen toegevoegd, nooit gewijzigd. Gebruik de knop "Notitie" rechtsboven.</p>
    @forelse ($notes as $note)
        <div class="pz-person">
            <span class="pz-avatar">{{ $note->user ? mb_strtoupper(mb_substr($note->user->name, 0, 1)) : '·' }}</span>
            <div>
                <div style="white-space:pre-line">{{ $note->payload['text'] ?? '' }}</div>
                <div class="pz-person__meta">{{ $note->user?->name ?? 'Systeem' }} · {{ $note->occurred_at->setTimezone($tz)->format('d-m-Y H:i') }}</div>
            </div>
        </div>
    @empty
        <p class="pz-empty">Nog geen notities.</p>
    @endforelse
</div>
