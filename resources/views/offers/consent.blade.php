<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Akkoord aanbieding — PHP Zoeker</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f7f4f8; color: #24132d; margin: 0; padding: 2rem 1rem; }
        main { max-width: 560px; margin: 0 auto; background: #fff; border: 1px solid #e8e1ec; border-radius: 16px; padding: 1.6rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .8rem; color: #431858; }
        dl { display: grid; grid-template-columns: 9rem 1fr; gap: .35rem .8rem; font-size: .92rem; }
        dt { color: #6b5f74; }
        textarea { width: 100%; box-sizing: border-box; border: 1px solid #e8e1ec; border-radius: 10px; padding: .6rem; font: inherit; margin-top: .8rem; }
        .buttons { display: flex; gap: .6rem; margin-top: 1rem; flex-wrap: wrap; }
        button { border-radius: 10px; padding: .6rem 1rem; font-weight: 600; border: 1px solid #431858; cursor: pointer; font: inherit; }
        .yes { background: #431858; color: #fff; }
        .no { background: #fff; color: #431858; }
        .done { padding: .8rem; border-radius: 10px; background: #e3f3ea; color: #17623f; }
        .done.no-consent { background: #fbe6ea; color: #b0132f; }
    </style>
</head>
<body>
<main>
    <h1>Aanbieding: {{ $offer->assignment->title }}</h1>
    <dl>
        <dt>Kandidaat</dt><dd>{{ $offer->candidate->name }}</dd>
        <dt>Opdrachtgever</dt><dd>{{ $offer->assignment->client_name ?? '—' }}</dd>
        <dt>Via</dt><dd>{{ $offer->viaName() }}</dd>
        <dt>Tarief</dt><dd>€ {{ number_format((float) $offer->offered_rate, 2, ',', '.') }} per uur, exclusief btw</dd>
    </dl>

    @if ($done !== null)
        <p @class(['done', 'no-consent' => $done === \App\Domain\Offers\Enums\ConsentStatus::Geweigerd])>
            {{ $done === \App\Domain\Offers\Enums\ConsentStatus::Geweigerd ? 'Je hebt aangegeven niet akkoord te gaan. Fremen biedt je niet aan op deze opdracht.' : 'Dank je! Je akkoord is vastgelegd.' }}
        </p>
    @elseif ($offer->status !== \App\Domain\Offers\Enums\OfferStatus::WachtOpKandidaat)
        <p class="done">Voor deze aanbieding is geen akkoord (meer) nodig.</p>
    @else
        <form method="post" action="{{ request()->fullUrl() }}">
            @csrf
            <label for="note">Opmerking (optioneel)</label>
            <textarea id="note" name="note" rows="3" maxlength="250"></textarea>
            <div class="buttons">
                <button class="yes" type="submit" name="decision" value="akkoord">Akkoord, bied me aan</button>
                <button class="no" type="submit" name="decision" value="geweigerd">Niet akkoord</button>
            </div>
        </form>
    @endif
</main>
</body>
</html>
