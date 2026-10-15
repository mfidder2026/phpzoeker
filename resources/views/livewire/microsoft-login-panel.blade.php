<div @if ($state === 'waiting') wire:poll.5s="check" @endif style="text-align:center">
    @if ($state === 'waiting')
        <p style="margin-bottom:.8rem">
            1. Klik op <strong>Naar de inlogpagina van Microsoft</strong>.<br>
            2. Vul daar deze code in en log in met een account dat toegang heeft tot <strong>{{ $mailbox ?: 'de mailbox' }}</strong>.
        </p>
        <div style="font-size:2rem;font-weight:700;letter-spacing:.2em;font-family:ui-monospace,monospace;color:var(--pz-primary);margin:.6rem 0"
             x-data x-on:click="navigator.clipboard?.writeText('{{ $userCode }}')" title="Klik om te kopiëren" role="button">
            {{ $userCode }}
        </div>
        <x-filament::button tag="a" :href="$verificationUri" target="_blank" rel="noopener noreferrer" icon="heroicon-o-arrow-top-right-on-square"
            x-on:click="navigator.clipboard?.writeText('{{ $userCode }}')">
            Naar de inlogpagina van Microsoft
        </x-filament::button>
        <p class="pz-person__meta" style="margin-top:1rem">
            De code staat ook op je klembord. Dit venster ziet vanzelf wanneer je klaar bent.<br>
            @if ($sending ?? false)
                Microsoft vraagt toestemming om mail te versturen (ook namens een gedeelde mailbox waar jij rechten op hebt). PHP Zoeker verstuurt alleen mails die een mens heeft goedgekeurd, vanuit {{ $mailbox ?: 'de ingestelde afzendermailbox' }}.
            @else
                Microsoft vraagt toestemming om mail te lezen en te markeren in mailboxen waar jij toegang toe hebt; PHP Zoeker gebruikt alleen {{ $mailbox ?: 'de ingestelde mailbox' }}.
            @endif
        </p>
        <div class="pz-bar pz-bar--indeterminate" style="max-width:240px;margin:1rem auto 0"><div class="pz-bar__fill"></div></div>
    @elseif ($state === 'connected')
        <div class="pz-alert pz-alert--success">✓ Verbonden. Je kunt dit venster sluiten en daarna {{ ($sending ?? false) ? '"Testmail sturen"' : '"Test run"' }} gebruiken.</div>
    @elseif ($state === 'expired')
        <div class="pz-alert pz-alert--danger">De code is verlopen.</div>
        <x-filament::button wire:click="start" style="margin-top:.8rem">Nieuwe code</x-filament::button>
    @elseif ($state === 'error')
        <div class="pz-alert pz-alert--danger">{{ $message }}</div>
        <x-filament::button wire:click="start" color="gray" style="margin-top:.8rem">Opnieuw proberen</x-filament::button>
    @endif
</div>
