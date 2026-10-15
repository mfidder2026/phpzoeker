# PHP Zoeker

Opdrachtenradar en aanbiedingsassistent voor Fremen-developers. Eén Filament-webapp met een vast linkermenu
(Laravel 13, Filament 5). Het ontwerp staat lokaal in `docs/ontwerp/` (niet in git: bevat persoonsgegevens); beslissingen in
[`docs/DECISIONS.md`](docs/DECISIONS.md); wat er per fase is opgeleverd in [`CHANGELOG.md`](CHANGELOG.md).

**Stand:** fase 0–4 zijn gebouwd: fundering, webapp met AI-agent en master-CV's, ingest van opdrachten, matching met
meldingen, en aanbieden met CV, mail en aanbiedingsregister. Rapportages (fase 5) staan als "Volgt in fase 5" in het menu.

## Lokaal starten (zonder Docker)

Vereist: PHP 8.4+ met `intl`, `zip`, `gd`, `pdo_sqlite`, `sodium`, en Composer.

```bash
composer install
cp .env.example .env        # vul AI_BOOTSTRAP_* en MAIL_* in; secrets alleen in .env
php artisan key:generate
php artisan migrate --seed  # rollen, beheerder, AI-agentprofiel, kandidaten
php artisan cv:build-template pad/naar/Fremen-CV.docx   # alleen nodig om de template opnieuw af te leiden
composer run dev            # webserver + queue-worker (ook de queue "ai")
```

Open http://localhost:8000 en log in met `ADMIN_EMAIL` / `ADMIN_PASSWORD` uit `.env` (leeg wachtwoord = het
seed-commando toont een willekeurig wachtwoord).

De kandidaat-seed leest `config/seed/candidates.yaml` (niet in git, bevat persoonsgegevens); zonder dat bestand
wordt het geanonimiseerde `candidates.example.yaml` gebruikt.

## Met Docker

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --seed --force
```

Diensten: app (8000), worker, scheduler, PostgreSQL 16 + pgvector (5432), Redis (6379), Gotenberg (3000, voor PDF),
Mailpit (8025).

## AI-agent

Provider, sleutel en modellen staan in **Instellingen → AI-agent** (versleuteld in de database). `.env` levert alleen
de startwaarde bij de eerste installatie. Activeer het profiel pas nadat de verwerkersovereenkomst is bevestigd, en
gebruik **Verbinding testen**. Elke AI-aanroep loopt via de `RunTracker` en is zichtbaar in de AI-meter en op de
pagina AI-activiteit.

## Opdrachten ophalen

Bronnen staan in **Beheer → Bronnen**. Een bron kan pas aan na de ToS-check. De scheduler (`php artisan schedule:work`) haalt actieve bronnen op volgens hun interval; handmatig toevoegen kan via **Opdrachten → Opdracht toevoegen** (URL of tekst).

Kwaliteit van de extractie meten (kost tokens):

```bash
php artisan eval:extraction
```

## Matching en meldingen

Elke nieuwe of bijgewerkte opdracht wordt automatisch gematcht met alle actieve kandidaten die een goedgekeurd
master-profiel hebben: voorselectie en harde filters zonder AI, daarna een AI-beoordeling per eis en wens, en een
score met opbouw (instellingen in **Instellingen → Matching**). Resultaten staan in **Werk → Matches**, op het
tabblad *Matches* van een opdracht en in de **Shortlist**-kanban. Sterke matches geven direct een melding; de
digest gaat op werkdagen om 08:00 (instelbaar in **Instellingen → Meldingen**). De worker moet daarvoor draaien
(`queue:work --queue=ai,ingest,documents,mail,default`), net als de scheduler.

Kwaliteit van de matching meten (kost tokens; de door Fremen gelabelde set hoort in `storage/app/private/eval/matching`):

```bash
php artisan eval:matching --map=senior=mark-van-der-zande --map=medior=sten-nierop
```

## Aanbieden

Vanuit een match: **Aanbieden** opent de wizard (controle → CV → mail → versturen). Het CV en de mail worden pas
verstuurd na goedkeuring door een mens; de grounding-check moet groen zijn. Na verzending staat de aanbieding in het
**Aanbiedingsregister** met een vaste snapshot. Aanbiedingen buiten de app leg je vast met **Handmatig registreren**
(.eml uploaden). De PDF van het CV maakt de app via Microsoft Word (Windows) of Gotenberg (`PDF_CONVERTER`).

## Kwaliteit

```bash
php artisan test --compact
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G
```

## Harde regels

Zie §0.1 van het ontwerpplan (R1–R10): geen e-mail naar externe partijen zonder menselijke goedkeuring, CV's alleen
op basis van het goedgekeurde master-profiel, geen scraping achter een login, geboortedatum nooit naar het
taalmodel, en geen AI-taak buiten het `AiRun`-mechanisme.
