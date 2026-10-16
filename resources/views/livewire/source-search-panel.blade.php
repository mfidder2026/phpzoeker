@php
    $duration = function (?int $seconds): string {
        if ($seconds === null) {
            return '';
        }
        if ($seconds < 60) {
            return $seconds.' s';
        }

        return intdiv($seconds, 60).' min'.($seconds % 60 > 0 && $seconds < 600 ? ' '.($seconds % 60).' s' : '');
    };
@endphp

<div @if ($progress && ! $progress['finished']) wire:poll.2s @endif>
    @if ($progress && $mode === 'minimized')
        <button type="button" class="pz-search-pill" wire:click="expand" aria-label="Voortgang zoeken tonen">
            <span @class(['pz-search-pill__dot', 'pz-search-pill__dot--done' => $progress['finished']])></span>
            @if ($progress['finished'])
                Zoeken klaar · {{ $progress['assignments'] }} {{ $progress['assignments'] === 1 ? 'nieuwe opdracht' : 'nieuwe opdrachten' }}
            @else
                Zoeken {{ $progress['percent'] }}% · {{ $progress['phase'] }}{{ $progress['remaining_seconds'] !== null ? ' · nog ± '.$duration($progress['remaining_seconds']) : '' }}
            @endif
        </button>
    @elseif ($progress && $mode === 'open')
        <div class="pz-progress-backdrop">
            <div class="pz-progress" role="dialog" aria-modal="true" aria-labelledby="pz-search-title">
                <div class="pz-progress__head">
                    <div style="flex:1">
                        <p id="pz-search-title" class="pz-progress__title" aria-live="polite">
                            {{ $progress['finished'] ? 'Zoeken klaar' : 'Zoeken — '.$progress['phase'] }}
                        </p>
                        <p class="pz-progress__subject">
                            {{ $duration($progress['elapsed_seconds']) }} verstreken
                            @if ($progress['remaining_seconds'] !== null)
                                · nog ongeveer {{ $duration($progress['remaining_seconds']) }}
                            @elseif (! $progress['finished'])
                                · resterende tijd wordt berekend…
                            @endif
                        </p>
                    </div>
                    <div class="pz-progress__pct">{{ $progress['percent'] }}%</div>
                </div>
                <div @class(['pz-bar', 'pz-bar--indeterminate' => ! $progress['finished'] && $progress['percent'] < 3]) style="margin-top:.75rem">
                    <div class="pz-bar__fill" style="width: {{ max(3, $progress['percent']) }}%"></div>
                </div>

                @if ($progress['worker_warning'])
                    <div class="pz-alert pz-alert--danger" style="margin-top:.75rem">{{ $progress['worker_warning'] }}</div>
                @endif

                <ol class="pz-steps">
                    @php
                        $fetchDone = collect($progress['sources'])->whereIn('status', ['done', 'failed'])->count();
                        $fetchTotal = count($progress['sources']);
                        $extractStatus = match (true) {
                            $fetchDone < $fetchTotal && $progress['postings_total'] === 0 => 'pending',
                            $progress['postings_done'] < $progress['postings_total'] => 'running',
                            default => $fetchDone < $fetchTotal ? 'running' : 'done',
                        };
                        $matchStatus = match (true) {
                            $progress['finished'] => 'done',
                            $progress['matching_queued'] > 0 => 'running',
                            default => 'pending',
                        };
                    @endphp

                    <li @class(['pz-step', 'pz-step--done' => $fetchDone === $fetchTotal, 'pz-step--running' => $fetchDone < $fetchTotal])>
                        <span class="pz-step__icon" aria-hidden="true">{{ $fetchDone === $fetchTotal ? '✓' : '◔' }}</span>
                        <span>1. Bronnen ophalen</span>
                        <span class="pz-step__time">{{ $fetchDone }} / {{ $fetchTotal }}</span>
                        <span class="pz-step__msg" style="white-space:normal">
                            @foreach ($progress['sources'] as $source)
                                <span @class(['pz-search-source', 'pz-search-source--failed' => $source['status'] === 'failed'])>{{ match ($source['status']) { 'done' => '✓', 'failed' => '✕', 'running' => '◔', default => '⏳' } }} {{ $source['name'] }} — {{ match ($source['status']) {
                                    'pending' => 'wacht',
                                    'running' => 'bezig ('.$source['fetched'].' bekeken)',
                                    'failed' => 'mislukt'.($source['message'] ? ': '.$source['message'] : ''),
                                    default => $source['fetched'].' bekeken, '.$source['new'].' nieuw',
                                } }}</span>
                            @endforeach
                        </span>
                    </li>

                    <li @class(['pz-step', 'pz-step--'.$extractStatus])>
                        <span class="pz-step__icon" aria-hidden="true">{{ match ($extractStatus) { 'done' => '✓', 'running' => '◔', default => '⏳' } }}</span>
                        <span>2. Nieuwe opdrachten lezen met AI</span>
                        <span class="pz-step__time">{{ $progress['postings_done'] }} / {{ $progress['postings_total'] }}</span>
                        <span class="pz-step__msg">
                            @if ($progress['postings_total'] === 0)
                                {{ $fetchDone < $fetchTotal ? 'Wacht op de bronnen…' : 'Geen nieuwe publicaties gevonden.' }}
                            @else
                                {{ $progress['assignments'] }} relevante {{ $progress['assignments'] === 1 ? 'opdracht' : 'opdrachten' }}{{ $progress['postings_failed'] > 0 ? ' · '.$progress['postings_failed'].' mislukt' : '' }}. Elke publicatie duurt meestal 10–30 seconden.
                            @endif
                        </span>
                    </li>

                    <li @class(['pz-step', 'pz-step--'.$matchStatus])>
                        <span class="pz-step__icon" aria-hidden="true">{{ match ($matchStatus) { 'done' => '✓', 'running' => '◔', default => '⏳' } }}</span>
                        <span>3. Kandidaten matchen</span>
                        <span class="pz-step__time">{{ $progress['matches'] }} {{ $progress['matches'] === 1 ? 'match' : 'matches' }}</span>
                        <span class="pz-step__msg">
                            @if ($progress['matching_queued'] > 0)
                                Nog {{ $progress['matching_queued'] }} {{ $progress['matching_queued'] === 1 ? 'opdracht' : 'opdrachten' }} te matchen.
                            @endif
                            {{ $progress['strong_matches'] }} {{ $progress['strong_matches'] === 1 ? 'sterke match' : 'sterke matches' }}.
                        </span>
                    </li>
                </ol>

                @if ($progress['finished'])
                    <div class="pz-alert pz-alert--success">
                        Klaar: {{ $progress['assignments'] }} {{ $progress['assignments'] === 1 ? 'nieuwe opdracht' : 'nieuwe opdrachten' }} en {{ $progress['matches'] }} {{ $progress['matches'] === 1 ? 'match' : 'matches' }}.
                    </div>
                @endif

                <div class="pz-progress__actions">
                    @if ($progress['finished'])
                        <a class="pz-btn" href="{{ \App\Filament\Resources\Assignments\AssignmentResource::getUrl() }}" wire:click="close">Naar opdrachten</a>
                        <button type="button" class="pz-btn pz-btn--primary" x-on:click="$wire.close().then(() => window.location.reload())">Sluiten</button>
                    @else
                        <button type="button" class="pz-btn pz-btn--primary" wire:click="minimize">Op de achtergrond verder</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
