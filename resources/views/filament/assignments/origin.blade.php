@php
    /** @var \App\Domain\Assignments\Models\Assignment $assignment */
    $assignment ??= $getRecord();
    $links = $assignment->sources()->with(['source', 'rawPosting', 'organization', 'contact'])->orderBy('first_seen_at')->get();
    $client = $assignment->client_name ?? $assignment->clientOrganization?->name;
    $intermediary = $assignment->intermediary_name ?? $assignment->intermediaryOrganization?->name;
@endphp

<div class="pz-origin">
    <dl class="pz-dl">
        <dt>Opdrachtgever</dt><dd>{{ $client ?? 'Onbekend' }}</dd>
        <dt>Intermediair</dt><dd>{{ $intermediary ?? 'Onbekend' }}</dd>
    </dl>

    @foreach ($links as $link)
        @php
            $platform = $link->platformName();
            $contactEmail = $link->contact_email ?? $link->contact?->email;
        @endphp
        <div class="pz-person">
            <span class="pz-chip">{{ $link->isAlertMail() ? 'Job alert' : $link->source->type->getLabel() }}</span>
            <div style="min-width:0">
                <div class="pz-person__name">{{ $platform }}</div>
                <div class="pz-person__meta">
                    @if ($link->isAlertMail()) Alert-mail in de aanvragen-mailbox · @endif
                    binnengekomen {{ $link->first_seen_at?->format('d-m-Y H:i') }}
                </div>
                @if ($contactEmail)
                    <div class="pz-person__meta">Contact: {{ $link->contact_name ?? $link->contact?->name ?? '—' }} · {{ $contactEmail }}</div>
                @endif
            </div>
            @if ($link->url)
                <div class="pz-person__aside">
                    <a class="pz-btn" href="{{ $link->url }}" target="_blank" rel="noopener noreferrer">Opdracht openen bij {{ $platform }}</a>
                </div>
            @endif
        </div>
        @if ($link->isAlertMail() && ! $contactEmail)
            <div class="pz-alert pz-alert--warning">
                Deze opdracht kwam binnen als alert van {{ $platform }}. Zo'n alert bevat alleen een samenvatting: de opdrachtgever en de contactpersoon staan er niet in.
                Open de opdracht bij {{ $platform }} om te zien voor wie hij is en hoe je reageert. Kan dat per mail, vul het adres dan in bij <strong>Mail bewerken → Aan</strong> en vink aan dat je het zelf hebt gecontroleerd.
                Gaat reageren via het platform zelf, kies dan bij <strong>Route, kanaal en CV-opties</strong> het kanaal <strong>Platformindiening</strong>.
            </div>
        @endif
    @endforeach
</div>
