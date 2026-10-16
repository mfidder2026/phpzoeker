<?php

namespace App\Livewire;

use App\Domain\Sources\Actions\SourceSearch;
use App\Filament\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Voortgang van "Nu zoeken": welke bron wordt opgehaald, hoeveel opdrachten de AI al heeft
 * gelezen, de matches en een schatting van de resterende tijd. Kan naar de achtergrond
 * (klein balkje rechtsonder) en blijft na het wisselen van pagina zichtbaar.
 */
class SourceSearchPanel extends Component
{
    /** open | minimized | hidden */
    public string $mode = 'hidden';

    public function mount(): void
    {
        $user = Access::user();

        if ($user !== null && app(SourceSearch::class)->current($user) !== null) {
            $this->mode = 'minimized';
        }
    }

    #[On('source-search-started')]
    public function show(): void
    {
        $this->mode = 'open';
    }

    public function expand(): void
    {
        $this->mode = 'open';
    }

    public function minimize(): void
    {
        $this->mode = 'minimized';
    }

    public function close(): void
    {
        $user = Access::user();

        if ($user !== null) {
            app(SourceSearch::class)->forget($user);
        }

        $this->mode = 'hidden';
    }

    public function render(): View
    {
        $user = Access::user();
        $search = $user !== null && $this->mode !== 'hidden' ? app(SourceSearch::class)->current($user) : null;

        if ($search === null && $this->mode !== 'hidden') {
            $this->mode = 'hidden';
        }

        return view('livewire.source-search-panel', [
            'progress' => $search !== null ? app(SourceSearch::class)->progress($search) : null,
        ]);
    }
}
