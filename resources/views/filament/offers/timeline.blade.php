@php
    /** @var \App\Domain\Offers\Models\Offer $record */
    $record = $getRecord();
    $tz = \App\Domain\Offers\Support\ResponseTerm::TIMEZONE;
    $activities = $record->activities()->with('user')->get();
@endphp

<ul class="pz-steps">
    @forelse ($activities as $activity)
        <li @class(['pz-step', 'pz-step--done' => in_array($activity->type->value, ['sent', 'cv_approved', 'mail_approved'], true), 'pz-step--failed' => $activity->type->value === 'error'])>
            <span class="pz-step__icon">•</span>
            <span><strong>{{ $activity->type->getLabel() }}</strong> — {{ $activity->summary() }}</span>
            <span class="pz-step__time">{{ $activity->occurred_at->setTimezone($tz)->format('d-m-Y H:i') }}{{ $activity->user ? ' · '.$activity->user->name : '' }}</span>
        </li>
    @empty
        <li class="pz-empty">Nog geen gebeurtenissen.</li>
    @endforelse
</ul>
