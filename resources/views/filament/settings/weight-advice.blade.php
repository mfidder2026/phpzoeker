@php
    $num = fn (?float $value): string => $value === null ? '—' : str_replace('.', ',', (string) $value);
@endphp

<div>
    <p class="pz-person__meta">
        Feedback: {{ $advice['up'] }} × 👍 en {{ $advice['down'] }} × 👎.
        @if ($advice['agreement'] !== null)
            Het label klopt in {{ $advice['agreement'] }}% van de beoordeelde matches met de feedback.
        @endif
        @unless ($advice['ready'])
            Een voorstel komt er vanaf {{ \App\Domain\Matching\Support\WeightAdvisor::MIN_PER_SIDE }} beoordelingen aan beide kanten.
        @endunless
    </p>
    <table class="pz-matrix">
        <thead><tr><th>Component</th><th>Gem. bij 👍</th><th>Gem. bij 👎</th><th>Nu</th><th>Voorstel</th></tr></thead>
        <tbody>
            @foreach ($advice['components'] as $component)
                <tr>
                    <td>{{ $component['label'] }}</td>
                    <td>{{ $num($component['up_mean']) }}</td>
                    <td>{{ $num($component['down_mean']) }}</td>
                    <td>{{ $component['current'] }}</td>
                    <td @class(['pz-ok' => $component['suggested'] > $component['current'], 'pz-fail' => $component['suggested'] < $component['current']])>{{ $component['suggested'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
