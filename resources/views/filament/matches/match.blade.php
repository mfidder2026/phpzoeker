@php
    /** @var \App\Domain\Matching\Models\CandidateMatch $match */
    $assessment = $match->assessment ?? [];
    $verdictChip = fn (string $verdict): string => match ($verdict) {
        'voldoet' => 'pz-chip--success',
        'deels' => 'pz-chip--warning',
        'voldoet_niet' => 'pz-chip--danger',
        default => '',
    };
    $outcomeIcon = fn (string $outcome): array => match ($outcome) {
        'ok' => ['✓', 'pz-ok'],
        'fail' => ['✕', 'pz-fail'],
        'warning' => ['!', 'pz-warn'],
        default => ['–', 'pz-skip'],
    };
    $recommendationChip = fn (string $recommendation): string => match ($recommendation) {
        'aanbieden' => 'pz-chip--success',
        'twijfel' => 'pz-chip--warning',
        default => 'pz-chip--danger',
    };
    $compact ??= false;
@endphp

<div class="pz-match">
    <div class="pz-match__head">
        <div class="pz-match__score pz-match__score--{{ $match->label->value }}">
            <span>{{ $match->score }}</span>
            <small>{{ $match->label->getLabel() }}</small>
        </div>
        <div class="pz-match__summary">
            @if ($match->recommendation)
                <span class="pz-chip {{ $recommendationChip($match->recommendation->value) }}">Advies: {{ $match->recommendation->getLabel() }}</span>
            @endif
            <span class="pz-chip">{{ $match->assessment_status->getLabel() }}</span>
            <span class="pz-chip">{{ $match->status->getLabel() }}</span>
            @if ($match->knockout_override_reason)
                <span class="pz-chip pz-chip--warning" title="{{ $match->knockout_override_reason }}">Knock-out overruled</span>
            @endif
            @if (filled($assessment['toelichting'] ?? null))
                <p class="pz-match__text">{{ $assessment['toelichting'] }}</p>
            @endif
            <p class="pz-person__meta">
                Berekend {{ $match->computed_at?->format('d-m-Y H:i') ?? '—' }}
                @if ($match->profile_version) · master-profiel v{{ $match->profile_version }} @endif
                @if ($match->prompt_version) · {{ $match->prompt_version }} @endif
                @if ($match->model) · {{ $match->model }} @endif
            </p>
        </div>
    </div>

    @if (filled($match->warnings))
        <div class="pz-alert pz-alert--warning pz-match__block">
            @foreach ($match->warnings as $warning)
                <div>⚠ {{ $warning }}</div>
            @endforeach
        </div>
    @endif

    <div class="pz-grid-2 pz-match__block">
        <div class="pz-card">
            <div class="pz-card__head"><h3 class="pz-card__title">Score-opbouw</h3></div>
            @foreach ($match->score_breakdown ?? [] as $component)
                <div class="pz-scorebar">
                    <div class="pz-scorebar__label">
                        <span>{{ $component['label'] }}</span>
                        <span>{{ str_replace('.', ',', (string) $component['points']) }} / {{ $component['weight'] }}</span>
                    </div>
                    <div class="pz-bar"><div class="pz-bar__fill" style="width: {{ (int) round($component['value'] * 100) }}%"></div></div>
                    <div class="pz-person__meta">{{ $component['detail'] }}</div>
                </div>
            @endforeach
        </div>

        <div class="pz-card">
            <div class="pz-card__head"><h3 class="pz-card__title">Harde filters</h3></div>
            <ul class="pz-checklist">
                @foreach ($match->filter_results ?? [] as $filter)
                    @php([$icon, $iconClass] = $outcomeIcon($filter['outcome']))
                    <li>
                        <span class="{{ $iconClass }}">{{ $icon }}</span>
                        <span><strong>{{ $filter['label'] }}</strong> <span class="pz-cv__muted">{{ $filter['detail'] }}</span></span>
                    </li>
                @endforeach
            </ul>
            <h4 class="pz-match__subtitle">Voorselectie</h4>
            <ul class="pz-checklist">
                <li>
                    <span class="{{ $match->prefilter_passed ? 'pz-ok' : 'pz-fail' }}">{{ $match->prefilter_passed ? '✓' : '✕' }}</span>
                    <span>{{ implode(' ', $match->prefilter_reasons ?? []) ?: '—' }}</span>
                </li>
            </ul>
        </div>
    </div>

    <div class="pz-card pz-match__block">
        <div class="pz-card__head"><h3 class="pz-card__title">Eisen en wensen</h3></div>
        @if ($assessment === [])
            <p class="pz-empty">Nog geen AI-beoordeling{{ $match->assessment_status === \App\Domain\Matching\Enums\AssessmentStatus::NietNodig ? ': de voorselectie of een harde filter is niet gehaald.' : '.' }}</p>
        @endif
        <div class="pz-matrix-wrap">
            <table class="pz-matrix">
                <thead><tr><th></th><th>Eis of wens</th><th>Oordeel</th><th>Bewijs uit het master-profiel</th></tr></thead>
                <tbody>
                    @foreach ([$match->requirementRows(), $match->wishRows()] as $rows)
                        @foreach ($rows as $row)
                            <tr>
                                <td><span @class(['pz-chip', 'pz-chip--danger' => $row['knock_out'] ?? false, 'pz-chip--info' => str_starts_with((string) $row['id'], 'W')])>{{ $row['id'] }}</span></td>
                                <td>
                                    {{ $row['tekst'] ?? '' }}
                                    @if ($row['knock_out'] ?? false)<div class="pz-person__meta">knock-out</div>@endif
                                </td>
                                <td>
                                    <span class="pz-chip {{ $verdictChip($row['oordeel']) }}">{{ \App\Domain\Matching\Enums\Verdict::tryFrom($row['oordeel'])?->getLabel() ?? $row['oordeel'] }}</span>
                                    @if ($row['gecorrigeerd'])<div class="pz-person__meta" title="Gecorrigeerd op basis van de berekende jaren ervaring">gecorrigeerd</div>@endif
                                </td>
                                <td>
                                    {{ $row['bewijs'] ?? '—' }}
                                    @if ($row['bron_in_profiel'])<div class="pz-person__meta"><code>{{ $row['bron_in_profiel'] }}</code></div>@endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if (filled($assessment['sterke_punten'] ?? null) || filled($assessment['risicos'] ?? null))
        <div class="pz-grid-2 pz-match__block">
            <div class="pz-card">
                <div class="pz-card__head"><h3 class="pz-card__title">Sterke punten</h3></div>
                <ul class="pz-checklist">
                    @foreach ($assessment['sterke_punten'] ?? [] as $point)
                        <li><span class="pz-ok">+</span><span>{{ $point }}</span></li>
                    @endforeach
                </ul>
            </div>
            <div class="pz-card">
                <div class="pz-card__head"><h3 class="pz-card__title">Risico’s</h3></div>
                <ul class="pz-checklist">
                    @foreach ($assessment['risicos'] ?? [] as $risk)
                        <li><span class="pz-fail">−</span><span>{{ $risk }}</span></li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($match->feedback || $match->candidate_interest || $match->rejection_reason)
        <p class="pz-person__meta pz-match__block">
            @if ($match->feedback) Feedback: {{ $match->feedback === 'up' ? '👍' : '👎' }} {{ $match->feedback_reason }} @endif
            @if ($match->candidate_interest) · Kandidaat: {{ $match->candidate_interest === 'up' ? '👍' : '👎' }} {{ $match->candidate_interest_reason }} @endif
            @if ($match->rejection_reason) · Afgewezen: {{ $match->rejection_reason }} @endif
        </p>
    @endif
</div>
