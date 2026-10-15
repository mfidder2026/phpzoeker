# PHP Zoeker

Opdrachtenradar en aanbiedingsassistent voor Fremen-developers. Eén Filament-webapp met een vast linkermenu
(Laravel 13, Filament 5). Het ontwerp staat lokaal in `docs/ontwerp/` (niet in git: bevat persoonsgegevens); beslissingen in
[`docs/DECISIONS.md`](docs/DECISIONS.md); wat er per fase is opgeleverd in [`CHANGELOG.md`](CHANGELOG.md).

**Stand:** fase 0 (fundering) en fase 1 (webapp, AI-agent, kandidaten, master-CV) zijn gebouwd. Ingest,
matching en aanbiedingen volgen in fase 2–4 en staan al als "Volgt in fase x" in het menu.

## Lokaal starten (zonder Docker)

Vereist: PHP 8.4+ met `intl`, `zip`, `gd`, `pdo_sqlite`, `sodium`, en Composer.

```bash
composer install
cp .env.example .env        # vul AI_BOOTSTRAP_* en MAIL_* in; secrets alleen in .env
php artisan key:generate
php artisan migrate --seed  # rollen, beheerder, AI-agentprofiel, kandidaten
php artisan cv:build-template
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
