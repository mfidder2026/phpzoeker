@php
    /** @var \App\Domain\Offers\Models\Offer $record */
    $record = $getRecord();
    $tz = \App\Domain\Offers\Support\ResponseTerm::TIMEZONE;
    $euro = fn ($amount): string => $amount === null ? '—' : '€ '.number_format((float) $amount, 2, ',', '.');
    $snapshot = $record->candidate_snapshot ?? [];
    $indicator = \App\Domain\Offers\Support\ResponseTerm::indicator($record);
    $rows = [
        'Kandidaat' => $record->candidate->name,
        'Opdracht' => $record->assignment->title.($record->assignment->reference ? ' ('.$record->assignment->reference.')' : ''),
        'Eindklant' => $record->assignment->client_name ?? '—',
        'Via' => $record->viaName().($record->contact ? ' · '.trim(($record->contact->name ?? '').' '.($record->contact->email ? '<'.$record->contact->email.'>' : '')) : ''),
        'Kanaal' => $record->channel->getLabel().($record->is_manual ? ' (handmatig geregistreerd)' : ''),
        'Aangeboden op' => $record->sent_at?->setTimezone($tz)->format('d-m-Y H:i') ?? 'Nog niet verzonden',
        'Aangeboden door' => $record->sentBy?->name ?? '—',
        'Goedgekeurd door' => $record->approvedBy?->name ? $record->approvedBy->name.' op '.$record->approved_at?->setTimezone($tz)->format('d-m-Y H:i') : '—',
        'Tarief' => $euro($record->offered_rate).' (max. opdracht: '.$euro($record->assignment->max_rate).')',
        'Beschikbaarheid' => ($snapshot['available_from'] ?? null) ? 'vanaf '.\Carbon\Carbon::parse($snapshot['available_from'])->format('d-m-Y') : 'per direct',
        'Uren' => ($snapshot['hours_per_week'] ?? '—').' uur per week',
        'Akkoord kandidaat' => $record->consent_status->getLabel().($record->consent_at ? ' ('.$record->consent_at->setTimezone($tz)->format('d-m-Y H:i').')' : ''),
        'Matchscore toen' => $record->match_score !== null ? (string) $record->match_score : '—',
        'Externe referentie' => $record->external_reference ?? '—',
        'Intake' => $record->intake_at ? $record->intake_at->setTimezone($tz)->format('d-m-Y H:i').(filled($record->intake['location'] ?? null) ? ' · '.$record->intake['location'] : '') : '—',
    ];
@endphp

@php($suggestion = \App\Domain\Offers\Actions\TrackReplies::pendingSuggestion($record))
@if ($suggestion)
    <div class="pz-alert pz-alert--warning" style="margin-top:0;margin-bottom:1rem">
        <strong>Reactie ontvangen</strong> van {{ $suggestion['from'] }} op {{ $suggestion['occurred_at']->setTimezone($tz)->format('d-m-Y H:i') }}: “{{ $suggestion['subject'] }}”<br>
        <span class="pz-person__meta">{{ $suggestion['preview'] }}</span><br>
        Voorstel: zet de status op <em>Reactie ontvangen</em> (knop “Reactie ontvangen” rechtsboven) of leg een intake vast.
    </div>
@endif

<div class="pz-grid-2 pz-match">
    <div class="pz-card">
        <dl class="pz-dl">
            @foreach ($rows as $label => $value)
                <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
            @endforeach
            @foreach ($snapshot['absences'] ?? [] as $absence)
                <dt>Afwezigheid</dt><dd>{{ ucfirst($absence['type']) }} {{ \Carbon\Carbon::parse($absence['starts_on'])->format('d-m-Y') }} t/m {{ \Carbon\Carbon::parse($absence['ends_on'])->format('d-m-Y') }}</dd>
            @endforeach
        </dl>
    </div>
    <div class="pz-card">
        <div class="pz-card__head"><h3 class="pz-card__title">Verwachte reactie</h3></div>
        @if ($record->expected_response_at)
            <p style="font-size:1.4rem;font-weight:700">{{ $record->expected_response_at->setTimezone($tz)->format('d-m-Y H:i') }}</p>
            <p>
                <span @class(['pz-chip', 'pz-chip--success' => $indicator['state'] === 'ok', 'pz-chip--warning' => $indicator['state'] === 'today', 'pz-chip--danger' => $indicator['state'] === 'late'])>{{ $indicator['label'] }}</span>
            </p>
            <p class="pz-person__meta" style="margin-top:.6rem">
                Later van verzending en deadline{{ $record->assignment->deadline_at ? ' ('.$record->assignment->deadline_at->setTimezone($tz)->format('d-m-Y H:i').')' : '' }}
                + {{ $record->response_term_days }} werkdagen ({{ \App\Domain\Offers\Support\ResponseTerm::sourceLabel($record->response_term_source) }}){{ $record->response_term_override_reason ? ' — '.$record->response_term_override_reason : '' }}.
                Weekend en Nederlandse feestdagen tellen niet mee.
            </p>
            @if ($record->follow_up_count > 0)
                <p class="pz-person__meta">{{ $record->follow_up_count }}× opgevolgd, laatst op {{ $record->last_follow_up_at?->setTimezone($tz)->format('d-m-Y') }}.</p>
            @endif
            @if ($record->response_received_at)
                <p class="pz-person__meta">Reactie ontvangen op {{ $record->response_received_at->setTimezone($tz)->format('d-m-Y H:i') }} (na {{ str_replace('.', ',', (string) $record->response_time_workdays) }} werkdagen).</p>
            @endif
            @if (app(\App\Domain\Offers\Actions\OfferWorkflow::class)->suggestsNoResponse($record))
                <div class="pz-alert pz-alert--warning">Na {{ $record->follow_up_count }} opvolgingen nog geen reactie. Overweeg de status "Geen reactie".</div>
            @endif
        @else
            <p class="pz-empty">Wordt berekend zodra de aanbieding is verzonden.</p>
        @endif
        @if ($record->status_reason)
            <p class="pz-person__meta" style="margin-top:.6rem">Laatste statusreden: {{ $record->status_reason }}</p>
        @endif
    </div>
</div>
