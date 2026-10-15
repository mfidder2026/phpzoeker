@php
    /** @var \App\Ai\Config\AiAgentProfile|null $profile */
    $result = $profile?->last_test_result;
@endphp

@if (! $profile?->last_test_at || ! $result)
    <p class="pz-empty">Nog niet getest. Klik op "Verbinding testen".</p>
@else
    <p style="margin-bottom:.6rem;font-size:.9rem">
        Laatst getest: {{ $profile->last_test_at->isToday() ? 'vandaag '.$profile->last_test_at->format('H:i') : $profile->last_test_at->format('d-m-Y H:i') }} —
        @if ($result['passed'] ?? false)
            <span class="pz-chip pz-chip--success">✓ geslaagd</span>
        @else
            <span class="pz-chip pz-chip--danger">✕ mislukt</span>
        @endif
    </p>
    @include('filament.ai.checklist', ['steps' => $result['steps'] ?? []])
@endif
