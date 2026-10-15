<x-filament-panels::page>
    <div style="max-width:280px">
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="candidateId">
                <option value="">Alle kandidaten</option>
                @foreach ($candidates as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    <div class="pz-kanban" x-data="{ dragging: null, over: null }">
        @foreach ($labels as $key => $label)
            <section
                @class(['pz-kanban__col'])
                :class="{ 'pz-kanban__col--drop': over === '{{ $key }}' }"
                x-on:dragover.prevent="over = '{{ $key }}'"
                x-on:dragleave="over = null"
                x-on:drop.prevent="if (dragging) { $wire.move(dragging, '{{ $key }}') } dragging = null; over = null"
                aria-label="{{ $label }}"
            >
                <div class="pz-kanban__head">
                    <span>{{ $label }}</span>
                    <span class="pz-chip">{{ $columns[$key]->count() }}</span>
                </div>

                @forelse ($columns[$key] as $match)
                    @php($deadline = $match->assignment->deadline_at)
                    <article class="pz-kanban__card" draggable="true" wire:key="match-{{ $match->id }}"
                             x-on:dragstart="dragging = {{ $match->id }}" x-on:dragend="dragging = null">
                        <a href="{{ $matchUrl($match) }}">{{ $match->assignment->title }}</a>
                        <div class="pz-person__meta">{{ $match->candidate->name }} · {{ collect([$match->assignment->client_name, $match->assignment->city])->filter()->implode(' · ') }}</div>
                        <div class="pz-kanban__card-foot">
                            <span>
                                <span @class(['pz-chip', 'pz-chip--success' => $match->label->value === 'sterk', 'pz-chip--warning' => $match->label->value === 'mogelijk'])>{{ $match->score }}</span>
                                @if ($deadline)
                                    <span @class(['pz-chip', 'pz-chip--danger' => $deadline->isFuture() && $deadline->lt(now()->addDay())])>{{ $deadline->format('d-m H:i') }}</span>
                                @endif
                                @if (filled($match->warnings))
                                    <span class="pz-chip pz-chip--warning" title="{{ implode("\n", $match->warnings) }}">⚠ {{ count($match->warnings) }}</span>
                                @endif
                            </span>
                            <span class="pz-kanban__moves">
                                @foreach ($labels as $target => $targetLabel)
                                    @if ($target !== $key)
                                        <button type="button" wire:click="move({{ $match->id }}, '{{ $target }}')" title="Naar {{ $targetLabel }}">→ {{ $targetLabel }}</button>
                                    @endif
                                @endforeach
                            </span>
                        </div>
                    </article>
                @empty
                    <p class="pz-empty">Leeg</p>
                @endforelse
            </section>
        @endforeach

        @foreach ($later as $label)
            <section class="pz-kanban__col pz-kanban__col--later" aria-label="{{ $label }}">
                <div class="pz-kanban__head"><span>{{ $label }}</span></div>
                <p class="pz-empty">Volgt in fase 4 (aanbiedingen).</p>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
