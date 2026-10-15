<!DOCTYPE html>
<html lang="{{ $english ? 'en' : 'nl' }}">
<head><meta charset="utf-8"><title></title></head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; color: #24132d;">
<p>{{ $greeting }}</p>
<p>{{ $intro }}</p>
<p>{{ $english ? 'Why '.$candidateFirstName.' is a good fit for this assignment:' : 'Waarom '.$candidateFirstName.' goed aansluit op deze opdracht:' }}</p>
<ul>
@foreach ($bullets as $bullet)
    <li>{{ $bullet }}</li>
@endforeach
</ul>
<p>{{ $english ? 'Practical details:' : 'Praktisch:' }}</p>
<ul>
@foreach ($practical as $line)
    <li>{{ $line }}</li>
@endforeach
</ul>
<p>{{ $english
    ? 'Please find attached the CV, tailored to this assignment. '.$candidateFirstName.' is available for an introductory meeting at short notice.'
    : 'In de bijlage vindt u het cv, afgestemd op deze opdracht. '.$candidateFirstName.' is op korte termijn beschikbaar voor een kennismakingsgesprek.' }}</p>
<p>{{ $english ? 'Kind regards,' : 'Met vriendelijke groet,' }}</p>
@if (filled($signatureHtml))
{!! $signatureHtml !!}
@else
<p>{{ $senderName }}</p>
@endif
</body>
</html>
