@php
    /** @var \App\Domain\Offers\Models\Offer $record */
    $record = $getRecord();
    $snapshot = $record->snapshot;
    $intact = $snapshot?->isIntact();
    $tz = \App\Domain\Offers\Support\ResponseTerm::TIMEZONE;

    if ($snapshot !== null && $intact === false) {
        activity('aanbiedingen')->performedOn($record)->withProperties(['snapshot_id' => $snapshot->id])->log('Snapshot-hash klopt niet');
    }

    // Afgeschermde weergave: geen scripts, geen externe bronnen (§11.7).
    $csp = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:;">';
@endphp

<div class="pz-match">
    @if ($snapshot === null)
        <p class="pz-empty">Er is nog geen snapshot: die wordt vastgelegd op het moment van verzending.</p>
    @else
        <div class="pz-card__head">
            <div>
                @if ($intact)
                    <span class="pz-chip pz-chip--success">✓ Ongewijzigd sinds verzending</span>
                @else
                    <span class="pz-chip pz-chip--danger">✕ De snapshot is gewijzigd of een bestand ontbreekt — de beheerder is gewaarschuwd</span>
                @endif
                @if ($snapshot->kind === \App\Domain\Offers\Models\OfferSnapshot::KIND_MANUAL)
                    <span class="pz-chip">Handmatig vastgelegd</span>
                @elseif ($snapshot->kind === \App\Domain\Offers\Models\OfferSnapshot::KIND_PLATFORM)
                    <span class="pz-chip">Platformindiening</span>
                @endif
            </div>
            <div style="display:flex;gap:.5rem">
                @if ($snapshot->eml_path)
                    <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-envelope" :href="\App\Http\Controllers\OfferFileController::url($record, $snapshot->eml_path, 'Aanbieding '.$record->id.'.eml')">Download .eml</x-filament::button>
                @endif
                <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-archive-box-arrow-down" :href="\App\Http\Controllers\OfferFileController::snapshotZipUrl($record)">Download alles (zip)</x-filament::button>
            </div>
        </div>

        @if ($snapshot->email_headers)
            <div class="pz-card pz-match__block">
                <dl class="pz-dl">
                    <dt>Van</dt><dd>{{ $snapshot->email_headers['from'] ?? '' }}</dd>
                    <dt>Aan</dt><dd>{{ implode(', ', (array) ($snapshot->email_headers['to'] ?? [])) }}</dd>
                    <dt>CC</dt><dd>{{ implode(', ', (array) ($snapshot->email_headers['cc'] ?? [])) ?: '—' }}</dd>
                    <dt>BCC</dt><dd>{{ implode(', ', (array) ($snapshot->email_headers['bcc'] ?? [])) ?: '—' }}</dd>
                    <dt>Onderwerp</dt><dd>{{ $snapshot->email_headers['subject'] ?? '' }}</dd>
                    <dt>Verzonden</dt><dd>{{ isset($snapshot->email_headers['date']) ? \Carbon\Carbon::parse($snapshot->email_headers['date'])->setTimezone($tz)->format('d-m-Y H:i') : '—' }}</dd>
                    <dt>Message-ID</dt><dd><code>{{ $snapshot->email_headers['message_id'] ?? '—' }}</code></dd>
                </dl>
            </div>
            @if ($snapshot->email_html)
                <iframe class="pz-mailframe pz-match__block" sandbox="" referrerpolicy="no-referrer" title="Verzonden mail" srcdoc="{{ $csp.$snapshot->email_html }}"></iframe>
            @else
                <pre class="pz-card pz-match__block" style="white-space:pre-wrap">{{ $snapshot->email_text }}</pre>
            @endif
        @endif

        <div class="pz-card pz-match__block">
            <div class="pz-card__head"><h3 class="pz-card__title">Bijlagen</h3></div>
            @forelse ($snapshot->attachments ?? [] as $file)
                <div class="pz-person">
                    <span class="pz-chip">{{ strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION)) }}</span>
                    <div>
                        <div class="pz-person__name">{{ $file['name'] }}</div>
                        <div class="pz-person__meta">{{ number_format($file['size'] / 1024, 0, ',', '.') }} kB · SHA-256 <code>{{ \Illuminate\Support\Str::limit($file['sha256'], 16, '…') }}</code></div>
                    </div>
                    <div class="pz-person__aside">
                        <x-filament::button tag="a" size="sm" color="gray" :href="\App\Http\Controllers\OfferFileController::url($record, $file['path'], $file['name'])">Download</x-filament::button>
                    </div>
                </div>
                @if ($file['mime'] === 'application/pdf')
                    <iframe class="pz-pdfframe" title="{{ $file['name'] }}" src="{{ \App\Http\Controllers\OfferFileController::url($record, $file['path'], $file['name'], inline: true) }}"></iframe>
                @endif
            @empty
                <p class="pz-empty">Geen bijlagen vastgelegd.</p>
            @endforelse
        </div>

        <p class="pz-person__meta pz-match__block">
            Vastgelegd op {{ $snapshot->captured_at->setTimezone($tz)->format('d-m-Y H:i:s') }}{{ $snapshot->capturedBy ? ' door '.$snapshot->capturedBy->name : '' }} ·
            SHA-256 <code>{{ $snapshot->sha256 }}</code>
            @if ($snapshot->provenance)
                · master-profiel v{{ $snapshot->provenance['profile_version'] ?? '?' }}, CV v{{ $snapshot->provenance['cv_version'] ?? '?' }} ({{ $snapshot->provenance['cv_prompt_version'] ?? '—' }}), mail {{ $snapshot->provenance['mail_prompt_version'] ?? '—' }}
            @endif
        </p>
    @endif
</div>
