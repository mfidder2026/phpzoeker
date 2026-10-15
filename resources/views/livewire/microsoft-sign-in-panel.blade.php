<div class="pz-signin" @if ($state === 'waiting') wire:poll.5s="check" @endif>
    @if ($state === 'idle')
        <div class="pz-signin__divider"><span>of</span></div>
        <x-filament::button wire:click="start" color="gray" class="w-full" icon="heroicon-o-building-office-2">
            Inloggen met Microsoft
        </x-filament::button>
    @elseif ($state === 'waiting')
        <div class="pz-signin__divider"><span>Inloggen met Microsoft</span></div>
        <p class="pz-person__meta" style="text-align:center">
            1. Klik op <strong>Naar Microsoft</strong> en log in met je Fremen-account.<br>
            2. Vul daar deze code in:
        </p>
        <div class="pz-signin__code" x-data x-on:click="navigator.clipboard?.writeText('{{ $userCode }}')" title="Klik om te kopiëren" role="button">{{ $userCode }}</div>
        <x-filament::button tag="a" :href="$verificationUri" target="_blank" rel="noopener noreferrer" class="w-full" icon="heroicon-o-arrow-top-right-on-square"
            x-on:click="navigator.clipboard?.writeText('{{ $userCode }}')">
            Naar Microsoft
        </x-filament::button>
        <p class="pz-person__meta" style="text-align:center;margin-top:.6rem">
            Deze pagina ziet vanzelf dat je bent ingelogd. Microsoft vraagt eenmalig toestemming; daarmee kan PHP Zoeker
            (voor een beheerder) ook mail versturen en de aanvragen-mailbox lezen.
        </p>
        <div class="pz-bar pz-bar--indeterminate" style="max-width:240px;margin:.6rem auto 0"><div class="pz-bar__fill"></div></div>
    @else
        <div class="pz-signin__divider"><span>Inloggen met Microsoft</span></div>
        <div class="pz-alert pz-alert--danger">{{ $state === 'expired' ? 'De code is verlopen.' : $message }}</div>
        <x-filament::button wire:click="start" color="gray" class="w-full" style="margin-top:.6rem">Opnieuw</x-filament::button>
    @endif
</div>
