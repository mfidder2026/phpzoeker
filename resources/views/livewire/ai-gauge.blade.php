@php
    $euro = fn (float $amount): string => '€ '.number_format($amount, 2, ',', '.');
    $circumference = 2 * M_PI * 15;
    $inner = 2 * M_PI * 10;
    $budgetColor = $budgetFraction >= 1 ? 'var(--pz-danger)' : ($budgetFraction >= 0.8 ? 'var(--pz-warning)' : 'var(--pz-primary)');
    $innerColor = match ($mode) {
        'busy' => 'var(--pz-primary)',
        'queue' => 'var(--pz-warning)',
        'error', 'offline', 'budget' => 'var(--pz-danger)',
        default => 'var(--pz-ring-track)',
    };
@endphp

<div
    wire:poll.{{ $pollSeconds }}s.visible
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    @class(['pz-gauge', 'pz-gauge--compact' => $compact])
    data-mode="{{ $mode }}"
>
    <button type="button" class="pz-gauge__button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true">
        <svg class="pz-gauge__ring" viewBox="0 0 36 36" aria-hidden="true">
            {{-- Buitenring: verbruik dagbudget --}}
            <circle cx="18" cy="18" r="15" fill="none" stroke="var(--pz-ring-track)" stroke-width="3" />
            <circle cx="18" cy="18" r="15" fill="none" stroke="{{ $budgetColor }}" stroke-width="3" stroke-linecap="round"
                stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $circumference * (1 - $budgetFraction) }}"
                transform="rotate(-90 18 18)" />
            {{-- Binnenring: voortgang lopende taken, of toestand --}}
            @if ($mode === 'busy' && $progress === null)
                <circle class="pz-spin" cx="18" cy="18" r="10" fill="none" stroke="{{ $innerColor }}" stroke-width="3" stroke-linecap="round"
                    stroke-dasharray="{{ $inner * 0.3 }} {{ $inner }}" />
            @elseif ($mode === 'busy')
                <circle cx="18" cy="18" r="10" fill="none" stroke="var(--pz-ring-track)" stroke-width="3" />
                <circle cx="18" cy="18" r="10" fill="none" stroke="{{ $innerColor }}" stroke-width="3" stroke-linecap="round"
                    stroke-dasharray="{{ $inner }}" stroke-dashoffset="{{ $inner * (1 - $progress / 100) }}" transform="rotate(-90 18 18)" />
            @elseif ($mode === 'queue')
                <circle class="pz-spin" cx="18" cy="18" r="10" fill="none" stroke="{{ $innerColor }}" stroke-width="3" stroke-dasharray="2 3" />
            @else
                <circle cx="18" cy="18" r="10" fill="none" stroke="{{ $innerColor }}" stroke-width="3" />
            @endif
            @if (in_array($mode, ['error', 'budget'], true))
                <text x="18" y="22.5" text-anchor="middle" font-size="12" font-weight="700" fill="var(--pz-danger)">!</text>
            @elseif ($mode === 'offline')
                <line x1="11" y1="25" x2="25" y2="11" stroke="var(--pz-danger)" stroke-width="2.5" stroke-linecap="round" />
            @endif
        </svg>
        <span class="pz-gauge__label">
            <span class="pz-gauge__title" aria-live="polite">{{ $label }}</span>
            <span class="pz-gauge__sub">{{ $euro($spent) }} van {{ $euro($budget) }} vandaag</span>
        </span>
    </button>

    <div class="pz-gauge__panel" x-show="open" x-cloak x-transition.opacity role="dialog" aria-label="AI-taken">
        <h3>Lopende en wachtende AI-taken</h3>
        @forelse ($open as $run)
            <div class="pz-run">
                <div class="pz-run__title">{{ $run->subject_label ?? $run->task->getLabel() }}</div>
                <div class="pz-run__meta">
                    {{ $run->agentLabel() }} · {{ $run->status->getLabel() }}
                    @if ($run->current_step)
                        @php
                            $steps = collect($run->steps ?? []);
                            $index = $steps->search(fn ($step) => $step['key'] === $run->current_step);
                        @endphp
                        · stap {{ $index === false ? '?' : $index + 1 }} van {{ $steps->count() }}
                    @endif
                    @if ($run->started_at)
                        · {{ $run->durationSeconds() }} s
                    @endif
                </div>
                @if ($run->current_detail)
                    <div class="pz-run__detail">{{ $run->current_detail }}</div>
                @endif
                <div @class(['pz-bar', 'pz-bar--indeterminate' => $run->progress === null]) role="progressbar"
                    aria-valuemin="0" aria-valuemax="100" @if ($run->progress !== null) aria-valuenow="{{ $run->progress }}" @endif>
                    <div class="pz-bar__fill" style="width: {{ $run->progress ?? 0 }}%"></div>
                </div>
            </div>
        @empty
            <p class="pz-empty">Er draaien nu geen AI-taken.</p>
        @endforelse

        @if ($recentFailure)
            <div class="pz-alert pz-alert--danger">
                Laatste fout ({{ $recentFailure->finished_at?->format('H:i') }}): {{ $recentFailure->subject_label ?? $recentFailure->task->getLabel() }} — {{ \Illuminate\Support\Str::limit((string) $recentFailure->error_message, 140) }}
            </div>
        @endif

        <div class="pz-gauge__footer">
            <a href="{{ $activityUrl }}">Naar AI-activiteit →</a>
        </div>
    </div>
</div>
