@php
    $record = $getRecord();
@endphp

<ol class="pz-steps">
    @forelse ($record->steps ?? [] as $step)
        <li class="pz-step pz-step--{{ $step['status'] }}">
            <span class="pz-step__icon" aria-hidden="true">{{ match ($step['status']) { 'done' => '✓', 'failed' => '✕', 'running' => '◔', 'skipped' => '–', default => '⏳' } }}</span>
            <span>{{ $step['label'] }} <span class="pz-cv__muted">({{ match ($step['status']) { 'done' => 'klaar', 'failed' => 'fout', 'running' => 'bezig', 'skipped' => 'overgeslagen', default => 'wachten' } }})</span></span>
            <span class="pz-step__time">gewicht {{ $step['weight'] }}</span>
            @if (! empty($step['error']))
                <span class="pz-step__msg">{{ $step['error'] }}</span>
            @endif
        </li>
    @empty
        <li class="pz-empty">Geen stappen.</li>
    @endforelse
</ol>

@if ($record->task === \App\Ai\Runs\AiTask::ConnectionTest && ! empty($record->result['steps']))
    <div style="margin-top:1rem">
        @include('filament.ai.checklist', ['steps' => $record->result['steps']])
    </div>
@endif
