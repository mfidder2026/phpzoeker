{!! $greeting !!}

{!! $intro !!}

{!! $english ? 'Why '.$candidateFirstName.' is a good fit for this assignment:' : 'Waarom '.$candidateFirstName.' goed aansluit op deze opdracht:' !!}
@foreach ($bullets as $bullet)
• {!! $bullet !!}
@endforeach

{!! $english ? 'Practical details:' : 'Praktisch:' !!}
@foreach ($practical as $line)
• {!! $line !!}
@endforeach

{!! $english
    ? 'Please find attached the CV, tailored to this assignment. '.$candidateFirstName.' is available for an introductory meeting at short notice.'
    : 'In de bijlage vindt u het cv, afgestemd op deze opdracht. '.$candidateFirstName.' is op korte termijn beschikbaar voor een kennismakingsgesprek.' !!}

{!! $english ? 'Kind regards,' : 'Met vriendelijke groet,' !!}

{!! filled($signatureText) ? $signatureText : $senderName !!}
