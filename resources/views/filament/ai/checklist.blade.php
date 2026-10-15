<ul class="pz-checklist" aria-label="Resultaat verbindingstest">
    @foreach ($steps as $index => $step)
        <li>
            @if ($step['passed'] === true)
                <span class="pz-ok" aria-label="geslaagd">✓</span>
            @elseif ($step['passed'] === false)
                <span class="pz-fail" aria-label="mislukt">✕</span>
            @else
                <span class="pz-skip" aria-label="overgeslagen">–</span>
            @endif
            <span>
                <strong>{{ $index + 1 }}. {{ $step['label'] }}</strong>
                @if (! empty($step['latency_ms']))
                    <span class="pz-cv__muted">· {{ $step['latency_ms'] }} ms</span>
                @endif
                @if (! empty($step['model']))
                    <span class="pz-cv__muted">· {{ $step['model'] }}</span>
                @endif
            </span>
            <span class="pz-checklist__msg">{{ $step['message'] }}</span>
        </li>
    @endforeach
</ul>
