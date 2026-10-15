@php
    $circumference = 2 * M_PI * 36;
@endphp

<div @if ($run && $run->status->isOpen()) wire:poll.1s @endif>
    @if ($run)
        @php
            $isOpen = $run->status->isOpen();
            $progress = $run->status === \App\Ai\Runs\AiRunStatus::Succeeded ? 100 : $run->progress;
            $color = match ($run->status) {
                \App\Ai\Runs\AiRunStatus::Failed => 'var(--pz-danger)',
                \App\Ai\Runs\AiRunStatus::Succeeded => 'var(--pz-success)',
                \App\Ai\Runs\AiRunStatus::Queued => 'var(--pz-warning)',
                default => 'var(--pz-primary)',
            };
            $testSteps = $run->task === \App\Ai\Runs\AiTask::ConnectionTest ? ($run->result['steps'] ?? null) : null;
        @endphp
        <div class="pz-progress-backdrop">
            <div class="pz-progress" role="dialog" aria-modal="true" aria-labelledby="pz-progress-title">
                <div class="pz-progress__head">
                    <svg class="pz-progress__big" viewBox="0 0 84 84" aria-hidden="true">
                        <circle cx="42" cy="42" r="36" fill="none" stroke="var(--pz-ring-track)" stroke-width="7" />
                        @if ($progress === null)
                            <circle class="pz-spin" cx="42" cy="42" r="36" fill="none" stroke="{{ $color }}" stroke-width="7" stroke-linecap="round"
                                stroke-dasharray="{{ $circumference * 0.25 }} {{ $circumference }}" />
                        @else
                            <circle cx="42" cy="42" r="36" fill="none" stroke="{{ $color }}" stroke-width="7" stroke-linecap="round"
                                stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $circumference * (1 - $progress / 100) }}"
                                transform="rotate(-90 42 42)" style="transition: stroke-dashoffset .4s ease" />
                        @endif
                        <text x="42" y="47" text-anchor="middle" font-size="16" font-weight="700" fill="var(--pz-text)">
                            {{ $progress === null ? '…' : $progress.'%' }}
                        </text>
                    </svg>
                    <div>
                        <p id="pz-progress-title" class="pz-progress__title" aria-live="polite">
                            {{ $run->task->getLabel() }} — {{ $run->status->getLabel() }}
                        </p>
                        <p class="pz-progress__subject">{{ $run->subject_label }}</p>
                        <p class="pz-progress__subject">
                            @if ($run->started_at)
                                {{ $run->durationSeconds() }} s verstreken
                            @else
                                Wacht op een vrije AI-worker…
                            @endif
                            @if ($run->model) · {{ $run->model }} @endif
                        </p>
                    </div>
                </div>

                <ol class="pz-steps">
                    @foreach ($run->steps ?? [] as $step)
                        @php
                            $duration = ! empty($step['started_at']) && ! empty($step['finished_at'])
                                ? \Illuminate\Support\Carbon::parse($step['started_at'])->diffInSeconds(\Illuminate\Support\Carbon::parse($step['finished_at']))
                                : null;
                        @endphp
                        <li class="pz-step pz-step--{{ $step['status'] }}">
                            <span class="pz-step__icon" aria-hidden="true">
                                {{ match ($step['status']) { 'done' => '✓', 'failed' => '✕', 'running' => '◔', 'skipped' => '–', default => '⏳' } }}
                            </span>
                            <span>
                                {{ $step['label'] }}
                                <span class="sr-only">({{ match ($step['status']) { 'done' => 'klaar', 'failed' => 'fout', 'running' => 'bezig', 'skipped' => 'overgeslagen', default => 'wachten' } }})</span>
                            </span>
                            <span class="pz-step__time">{{ $duration !== null ? round($duration, 1).' s' : '' }}</span>
                            @if ($step['status'] === 'running' && $run->current_detail)
                                <span class="pz-step__msg">{{ $run->current_detail }}</span>
                            @endif
                            @if (! empty($step['error']))
                                <span class="pz-step__msg">{{ $step['error'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>

                @if ($testSteps)
                    <div style="margin-top:1rem">
                        @include('filament.ai.checklist', ['steps' => $testSteps])
                    </div>
                @endif

                @if ($run->status === \App\Ai\Runs\AiRunStatus::Failed)
                    <div class="pz-alert pz-alert--danger">{{ $run->error_message }}</div>
                @elseif ($run->status === \App\Ai\Runs\AiRunStatus::Succeeded && ! $testSteps)
                    <div class="pz-alert pz-alert--success">Klaar. {{ $run->task === \App\Ai\Runs\AiTask::Cv && isset($run->result['version']) ? 'Conceptversie v'.$run->result['version'].' staat klaar voor review.' : '' }}</div>
                @elseif ($run->status === \App\Ai\Runs\AiRunStatus::Cancelled)
                    <div class="pz-alert pz-alert--danger">Geannuleerd.</div>
                @elseif ($run->cancel_requested)
                    <div class="pz-alert pz-alert--danger">Annuleren aangevraagd; de taak stopt na de huidige stap.</div>
                @endif

                <div class="pz-progress__actions">
                    @if ($isOpen)
                        <button type="button" class="pz-btn pz-btn--danger" wire:click="cancel" @disabled($run->cancel_requested)>Annuleren</button>
                        <button type="button" class="pz-btn pz-btn--primary" wire:click="background">Op de achtergrond verder</button>
                    @else
                        <button type="button" class="pz-btn pz-btn--primary" x-on:click="$wire.close().then(() => window.location.reload())">Sluiten</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
