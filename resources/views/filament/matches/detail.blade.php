@php
    /** @var \App\Domain\Matching\Models\CandidateMatch $record */
    $record = $getRecord();
@endphp

@include('filament.matches.match', ['match' => $record, 'compact' => false])
