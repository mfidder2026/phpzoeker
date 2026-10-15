@php
    /** @var \App\Domain\Sources\Data\ConnectorTestResult $result */
@endphp

<div>
    @if ($result->passed)
        <div class="pz-alert pz-alert--success">✓ {{ $result->message }}</div>
    @else
        <div class="pz-alert pz-alert--danger">✕ {{ $result->message }}</div>
    @endif

    @if ($result->samples !== [])
        <h4 style="margin:1rem 0 .4rem;font-weight:600">Eerste publicaties</h4>
        <ul class="pz-checklist">
            @foreach ($result->samples as $sample)
                <li>
                    <span>•</span>
                    <span>
                        <strong>{{ $sample['title'] }}</strong>
                        <span class="pz-cv__muted">· {{ $sample['published_at'] }}</span>
                        @if ($sample['url'])
                            <br><a href="{{ $sample['url'] }}" target="_blank" rel="noopener noreferrer" class="pz-card__link">{{ \Illuminate\Support\Str::limit($sample['url'], 80) }}</a>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
