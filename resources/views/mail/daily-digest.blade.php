<x-mail::message>
# Digest {{ $dateLabel }}

@forelse ($groups as $candidate => $items)
## {{ $candidate }}

@foreach ($items as $match)
- **[{{ $match->assignment->title }}]({{ $url($match) }})** — {{ $match->label->getLabel() }}, score {{ $match->score }}@if ($match->assignment->deadline_at), deadline {{ $match->assignment->deadline_at->format('d-m H:i') }}@endif
@if ($match->assignment->client_name || $match->assignment->city)
  {{ collect([$match->assignment->client_name, $match->assignment->city])->filter()->implode(' · ') }}
@endif
@if (filled($match->assessment['toelichting'] ?? null))
  {{ \Illuminate\Support\Str::words($match->assessment['toelichting'], 30) }}
@endif
@if (filled($match->warnings))
  ⚠ {{ implode(' · ', $match->warnings) }}
@endif
@endforeach

@empty
Er zijn sinds de vorige digest geen nieuwe sterke of mogelijke matches.
@endforelse

<x-mail::button :url="$matchesUrl">Alle matches</x-mail::button>

PHP Zoeker
</x-mail::message>
