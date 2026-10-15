@php
    /** @var \App\Domain\Assignments\Models\Assignment $record */
    $record = $getRecord();
    $offers = \App\Domain\Offers\Models\Offer::query()
        ->active()
        ->with(['candidate', 'organization', 'assignmentSource', 'assignment'])
        ->where('dedupe_group_id', $record->dedupe_group_id)
        ->get();
@endphp

@if ($offers->isNotEmpty())
    <div class="pz-alert pz-alert--warning" style="margin-top:0">
        @foreach ($offers as $offer)
            <div>
                <a class="pz-card__link" href="{{ \App\Filament\Resources\Offers\OfferResource::getUrl($offer->status->isPreparing() ? 'wizard' : 'view', ['record' => $offer]) }}">
                    {{ $offer->candidate->name }} {{ $offer->sent_at ? 'is al aangeboden via '.$offer->viaName().' op '.$offer->sent_at->format('d-m-Y') : 'heeft een aanbieding in voorbereiding via '.$offer->viaName() }}
                </a>
                · {{ $offer->status->getLabel() }}
            </div>
        @endforeach
    </div>
@endif
