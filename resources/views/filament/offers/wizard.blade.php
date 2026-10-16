@php
    /** @var \App\Domain\Offers\Models\Offer $offer */
    $steps = [1 => 'Controle', 2 => 'CV', 3 => 'Mail', 4 => 'Versturen'];
    $match = $offer->match;
    $platform = $offer->channel === \App\Domain\Offers\Enums\OfferChannel::Platform;
    $file = fn (?string $path, ?string $name = null, bool $inline = false): ?string => $path === null ? null : \App\Http\Controllers\OfferFileController::url($offer, $path, $name, $inline);
@endphp

<x-filament-panels::page>
    <div @if ($running) wire:poll.3s @endif class="pz-match">
        <ol class="pz-wizard">
            @foreach ($steps as $number => $label)
                <li @class(['pz-wizard__step', 'pz-wizard__step--done' => $step > $number, 'pz-wizard__step--current' => $step === $number])>
                    <span>{{ $step > $number ? '✓' : $number }}</span> {{ $label }}
                </li>
            @endforeach
        </ol>

        @if ($step >= 5)
            <div class="pz-alert pz-alert--success">
                Deze aanbieding is {{ mb_strtolower($offer->status->getLabel()) }}. <a class="pz-card__link" href="{{ \App\Filament\Resources\Offers\OfferResource::getUrl('view', ['record' => $offer]) }}">Bekijk hem in het register</a>.
            </div>
        @endif

        @if ($running)
            <div class="pz-alert pz-alert--warning">AI is bezig voor deze opdracht; deze pagina ververst vanzelf.</div>
        @endif

        {{-- Stap 1: controle --}}
        <section class="pz-card pz-match__block">
            <div class="pz-card__head">
                <h3 class="pz-card__title">1. Controle</h3>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">{{ $this->settingsAction }}</div>
            </div>
            <dl class="pz-dl">
                <dt>Route</dt><dd>{{ $offer->viaName() }}{{ $offer->assignmentSource?->contact_email ? ' · '.$offer->assignmentSource->contact_email : '' }}</dd>
                <dt>Kanaal</dt><dd>{{ $platform ? 'Platformindiening (interne mail met pakket, daarna zelf indienen)' : 'Mail' }}</dd>
                <dt>Tarief</dt><dd>€ {{ number_format((float) $offer->offered_rate, 2, ',', '.') }} per uur, excl. btw (max. opdracht: {{ $offer->assignment->max_rate !== null ? '€ '.number_format((float) $offer->assignment->max_rate, 2, ',', '.') : 'onbekend' }})</dd>
                <dt>Akkoord kandidaat</dt><dd>{{ $offer->consent_status === \App\Domain\Offers\Enums\ConsentStatus::Gevraagd ? 'Wordt gevraagd na het goedkeuren van de mail' : $offer->consent_status->getLabel() }}</dd>
            </dl>
            <h5 class="pz-match__subtitle">Voor wie en waar te reageren</h5>
            @include('filament.assignments.origin', ['assignment' => $offer->assignment])
            @foreach ($warnings as $warning)
                <div class="pz-alert pz-alert--warning">⚠ {{ $warning }}</div>
            @endforeach
            @if ($match)
                <details class="pz-match__block">
                    <summary><strong>Match: {{ $match->label->getLabel() }} ({{ $match->score }})</strong>{{ filled($match->warnings) ? ' · ⚠ '.implode(' · ', $match->warnings) : '' }}</summary>
                    @include('filament.matches.match', ['match' => $match, 'compact' => true])
                </details>
            @else
                <p class="pz-empty">Geen match berekend voor deze combinatie.</p>
            @endif
        </section>

        {{-- Stap 2: CV --}}
        <section class="pz-card pz-match__block">
            <div class="pz-card__head">
                <h3 class="pz-card__title">2. CV{{ $cv ? ' — versie '.$cv->version.($cv->edited ? ' (bewerkt)' : '') : '' }}</h3>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    {{ $this->generateCvAction }}
                    {{ $this->editCvAction }}
                    {{ $this->approveCvAction }}
                </div>
            </div>
            @if ($cv === null)
                <p class="pz-empty">Nog geen CV. Klik op "CV herschrijven": de app maakt een CV gericht op deze opdracht uit het goedgekeurde master-profiel.</p>
            @else
                @if ($cv->isApproved())
                    <div class="pz-alert pz-alert--success">✓ Goedgekeurd door {{ $cv->approvedBy?->name }} op {{ $cv->approved_at?->format('d-m-Y H:i') }}</div>
                @endif

                <div class="pz-grid-2 pz-match__block">
                    <div>
                        <h4 class="pz-match__subtitle">Grounding-rapport</h4>
                        @php($report = $cv->grounding_report ?? [])
                        <ul class="pz-checklist">
                            @foreach ($report['checks'] ?? [] as $check)
                                <li><span class="{{ $check['passed'] ? 'pz-ok' : 'pz-fail' }}">{{ $check['passed'] ? '✓' : '✕' }}</span><span><strong>{{ $check['check'] }}</strong> <span class="pz-cv__muted">{{ $check['detail'] }}</span></span></li>
                            @endforeach
                            <li>
                                <span class="{{ ($report['unsupported_claims'] ?? []) === [] && ($report['judge_error'] ?? null) === null ? 'pz-ok' : 'pz-fail' }}">{{ ($report['unsupported_claims'] ?? []) === [] && ($report['judge_error'] ?? null) === null ? '✓' : '✕' }}</span>
                                <span><strong>Beweringen onderbouwd (AI-rechter)</strong>
                                    @if ($report['judge_error'] ?? null)
                                        <span class="pz-cv__muted">Rechter niet beschikbaar: {{ $report['judge_error'] }}</span>
                                    @endif
                                </span>
                            </li>
                            @foreach ($report['unsupported_claims'] ?? [] as $claim)
                                <li><span class="pz-fail">!</span><span>"{{ $claim['claim'] }}" <span class="pz-cv__muted">· {{ $claim['location'] }} · {{ $claim['reason'] }}</span></span></li>
                            @endforeach
                            @foreach ($report['restored'] ?? [] as $restored)
                                <li><span class="pz-skip">↺</span><span class="pz-cv__muted">{{ $restored }}</span></li>
                            @endforeach
                        </ul>

                        <h4 class="pz-match__subtitle">Wat is er aangepast</h4>
                        @php($diff = $cv->diff_summary ?? [])
                        <ul class="pz-checklist">
                            @foreach ($cv->changes ?? [] as $change)
                                <li><span>•</span><span><strong>{{ $change['sectie'] ?? '' }}</strong> {{ $change['wijziging'] ?? '' }}</span></li>
                            @endforeach
                            @if (filled($diff['kernexpertise']['weggelaten'] ?? null))
                                <li><span>−</span><span>Weggelaten uit kernexpertise: {{ implode(', ', $diff['kernexpertise']['weggelaten']) }}</span></li>
                            @endif
                            @if (filled($diff['projecten']['weggelaten'] ?? null))
                                <li><span>−</span><span>Weggelaten projecten: {{ implode(', ', $diff['projecten']['weggelaten']) }}</span></li>
                            @endif
                        </ul>
                    </div>
                    <div>
                        <h4 class="pz-match__subtitle">Bestanden</h4>
                        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                            @if ($cv->pdf_path)
                                <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-document-arrow-down" :href="$file($cv->pdf_path)">PDF</x-filament::button>
                            @endif
                            @if ($cv->docx_path)
                                <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-document-arrow-down" :href="$file($cv->docx_path)">Word</x-filament::button>
                            @endif
                        </div>
                        @if ($cv->pdf_path === null)
                            <p class="pz-person__meta">PDF maken lukte niet (geen Word/Gotenberg beschikbaar); de mail krijgt dan het Word-bestand.</p>
                        @endif
                    </div>
                </div>

                <details class="pz-match__block" open>
                    <summary><strong>Verschil met het master-profiel</strong></summary>
                    <div class="pz-diff">
                        <div><h5>Master-profiel</h5><p>{{ $diff['profieltekst']['voor'] ?? '' }}</p></div>
                        <div><h5>Getailord</h5><p>{{ $diff['profieltekst']['na'] ?? '' }}</p></div>
                        @foreach ($diff['werkervaring'] ?? [] as $job)
                            @if ($job['gewijzigd'])
                                <div><h5>{{ $job['titel'] }}</h5><ul>@foreach ($job['bullets_voor'] as $bullet)<li>{{ $bullet }}</li>@endforeach</ul></div>
                                <div><h5>&nbsp;</h5><ul>@foreach ($job['bullets_na'] as $bullet)<li>{{ $bullet }}</li>@endforeach</ul></div>
                            @endif
                        @endforeach
                    </div>
                    @if (filled($cv->content['aansluiting_op_opdracht'] ?? null))
                        <h5 class="pz-match__subtitle">Aansluiting op de opdracht</h5>
                        <ul class="pz-checklist">
                            @foreach ($cv->content['aansluiting_op_opdracht'] as $row)
                                <li><span class="pz-chip">{{ $row['eis_id'] ?? '' }}</span><span>{{ $row['invulling'] ?? '' }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </details>

                @if ($cv->pdf_path)
                    <iframe class="pz-pdfframe pz-match__block" title="Voorbeeld CV" src="{{ $file($cv->pdf_path, null, true) }}"></iframe>
                @endif
            @endif
        </section>

        {{-- Stap 3: mail --}}
        <section class="pz-card pz-match__block">
            <div class="pz-card__head">
                <h3 class="pz-card__title">3. {{ $platform ? 'Indieningspakket en interne mail' : 'Mail' }}</h3>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    {{ $this->prepareMailAction }}
                    {{ $this->editMailAction }}
                    {{ $this->approveMailAction }}
                    {{ $this->reopenMailAction }}
                </div>
            </div>
            @if ($email === null)
                <p class="pz-empty">{{ $step < 3 && ! ($cv?->isApproved() ?? false) ? 'Keur eerst het CV goed.' : 'Nog geen concept.' }}</p>
            @else
                <dl class="pz-dl">
                    <dt>Van</dt><dd>{{ $email->from_name }} &lt;{{ $email->from }}&gt;</dd>
                    <dt>Aan</dt><dd>
                        @forelse ($email->to as $address)
                            {{ $address }} <span class="pz-chip">{{ $email->recipient_origins[$address] ?? 'onbekend' }}</span>
                        @empty
                            <span class="pz-fail">Geen ontvanger: vul die in via "Mail bewerken".</span>
                        @endforelse
                    </dd>
                    <dt>CC</dt><dd>{{ implode(', ', $email->cc ?? []) ?: '—' }}</dd>
                    <dt>BCC</dt><dd>{{ implode(', ', $email->bcc ?? []) ?: '—' }}</dd>
                    <dt>Onderwerp</dt><dd>{{ $email->subject }}</dd>
                    <dt>Bijlagen</dt><dd>
                        @foreach ($email->attachments ?? [] as $attachment)
                            <a class="pz-card__link" href="{{ $file($attachment['path'], $attachment['name']) }}">{{ $attachment['name'] }}</a> ({{ number_format($attachment['size'] / 1024, 0, ',', '.') }} kB)@if (! $loop->last), @endif
                        @endforeach
                    </dd>
                    <dt>Status</dt><dd><span class="pz-chip">{{ $email->status->getLabel() }}</span>{{ $email->approvedBy ? ' door '.$email->approvedBy->name : '' }}</dd>
                </dl>
                <iframe class="pz-mailframe pz-match__block" sandbox="" referrerpolicy="no-referrer" title="Voorbeeld mail" srcdoc="{{ $this->emailPreview($email) }}"></iframe>
            @endif
        </section>

        {{-- Stap 4: versturen --}}
        <section class="pz-card pz-match__block">
            <div class="pz-card__head">
                <h3 class="pz-card__title">4. {{ $platform ? 'Indienen' : 'Versturen' }}</h3>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    {{ $this->manualConsentAction }}
                    {{ $this->sendAction }}
                    {{ $this->retrySendAction }}
                    {{ $this->submittedAction }}
                </div>
            </div>
            @if ($offer->status === \App\Domain\Offers\Enums\OfferStatus::WachtOpKandidaat)
                <div class="pz-alert pz-alert--warning">Wacht op akkoord van {{ $offer->candidate->name }} (link verstuurd, 72 uur geldig). Bij een spoedige deadline kun je het akkoord handmatig registreren.</div>
            @elseif ($email?->status === \App\Domain\Offers\Enums\OutboundEmailStatus::Fout)
                <div class="pz-alert pz-alert--danger">Versturen mislukt: {{ $email->error }}</div>
            @elseif ($platform && $email?->status === \App\Domain\Offers\Enums\OutboundEmailStatus::Verzonden)
                <p>De interne mail met het pakket is verstuurd. Dien de kandidaat in via {{ $offer->viaName() }} en vul daarna het externe referentienummer in met "Ingediend".</p>
            @elseif ($offer->status === \App\Domain\Offers\Enums\OfferStatus::KlaarVoorVerzending)
                <p>Klaar om te {{ $platform ? 'versturen naar '.implode(', ', $email->to ?? []) : 'versturen' }}. Na verzending staat de aanbieding in het register, met een vaste snapshot van mail en CV.</p>
            @else
                <p class="pz-empty">Keur eerst het CV en de mail goed.</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
