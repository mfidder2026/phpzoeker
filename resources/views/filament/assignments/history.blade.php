@php
    /** @var \App\Domain\Assignments\Models\Assignment $record */
    $record = $getRecord();
    $activities = \Spatie\Activitylog\Models\Activity::query()->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->with('causer')->latest()->limit(50)->get();
    $runs = \App\Ai\Runs\AiRun::query()
        ->where(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id))
            ->orWhere(fn ($q) => $q->where('subject_type', (new \App\Domain\Sources\Models\RawPosting)->getMorphClass())->whereIn('subject_id', $record->rawPostings()->pluck('id'))))
        ->latest('created_at')->limit(20)->get();
@endphp

<div>
    @foreach ($runs as $run)
        <div class="pz-person">
            <span class="pz-chip pz-chip--info">AI</span>
            <div>
                <div class="pz-person__name">{{ $run->subject_label ?? $run->task->getLabel() }} — {{ $run->status->getLabel() }}</div>
                <div class="pz-person__meta">{{ $run->created_at->format('d-m-Y H:i') }} · {{ $run->model ?? '—' }} · {{ $run->prompt_version ?? '' }} · {{ $run->input_tokens }}/{{ $run->output_tokens }} tokens</div>
            </div>
        </div>
    @endforeach
    @foreach ($activities as $activity)
        <div class="pz-person">
            <span class="pz-chip">{{ $activity->event ?? 'log' }}</span>
            <div>
                <div class="pz-person__name">{{ $activity->description }}</div>
                <div class="pz-person__meta">
                    {{ $activity->created_at->format('d-m-Y H:i') }} · {{ $activity->causer?->name ?? 'Systeem' }}
                    @if ($changes = data_get($activity->attribute_changes, 'attributes'))
                        · {{ collect($changes)->keys()->implode(', ') }}
                    @endif
                </div>
            </div>
        </div>
    @endforeach
    @if ($runs->isEmpty() && $activities->isEmpty())
        <p class="pz-empty">Nog geen historie.</p>
    @endif
</div>
