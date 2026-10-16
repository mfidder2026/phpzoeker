# PHP Zoeker op Railway

Railway bouwt de app op zijn eigen servers met de `Dockerfile` in deze repo; lokaal is Docker niet nodig.
`railway.json` legt de build, de start (`railway/start.sh`) en de healthcheck (`/up`) vast.

## Opzet in Railway

Eén project met drie services:

| Service | Wat | Hoe |
|---|---|---|
| **phpzoeker** | Web + wachtrij-worker + planner in één container | *New → GitHub repo* → deze repo |
| **Postgres** | Database | *New → Database → PostgreSQL* |
| **gotenberg** | CV naar PDF (Word is er niet op Linux) | *New → Docker image* → `gotenberg/gotenberg:8` |

Web, worker en planner draaien bewust in één service: ze delen dan één **volume** voor de CV's, bijlagen en
uploads (een Railway-volume hoort bij één service).

1. Voeg bij **phpzoeker** een volume toe met mount path `/var/www/html/storage/app`.
2. Zet bij **phpzoeker** onder *Settings → Networking* een domein aan (*Generate Domain* of een eigen domein).
3. Vul de variabelen hieronder in en deploy.

Bij elke start voert `railway/start.sh` uit: migraties, rollen, de bronnencatalogus, de eerste beheerder
(alleen als die nog niet bestaat) en de caches. Daarna start het de worker, de planner en de webserver.

## Variabelen (service phpzoeker)

| Variabele | Waarde |
|---|---|
| `APP_NAME` | `PHP Zoeker` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | uitvoer van `php artisan key:generate --show` (lokaal) |
| `APP_URL` | `https://<jouw-domein>` |
| `APP_TIMEZONE` | `Europe/Amsterdam` |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` |
| `QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` | `database` |
| `LOG_CHANNEL` | `stderr` (logs in Railway) |
| `PDF_CONVERTER` | `gotenberg` |
| `GOTENBERG_URL` | `http://gotenberg.railway.internal:3000` |
| `ADMIN_EMAIL` / `ADMIN_NAME` / `ADMIN_PASSWORD` | eerste beheerder (wachtwoord als *sealed variable*) |
| `REQUIRE_MFA` | `true` aanbevolen |
| `SOURCES_AUTO_FETCH` | `false`: zoeken alleen met de knop **Nu zoeken** |
| `RUN_SCHEDULER` | `true`: reacties zoeken, herinneringen, digest en bewaartermijnen |
| `AI_BOOTSTRAP_*`, `MAIL_*` | zoals in `.env.example`; mail kan ook via *Inloggen met Microsoft* |

Zet geheimen (APP_KEY, wachtwoorden, API-keys) als *sealed variables*. Ze staan nooit in de repo.

## Na de eerste deploy

- Log in met het beheerdersaccount, of met **Inloggen met Microsoft** (dat koppelt ook verzenden en de aanvragen-mailbox).
- Instellingen → AI: controleer het AI-profiel en draai de verbindingstest.
- Instellingen → Mail: stuur een testmail.
- Microsoft-login gebruikt een eenmalige code; er is geen redirect-URL nodig, dus het domein maakt niet uit.

## Railway vanuit Claude Code

De Railway-MCP zit in de Railway CLI. Eenmalig, in een terminal:

```bash
railway login
```

Daarna kan Claude Code via de MCP-server `railway` projecten, services, variabelen, deploys en logs bekijken
en beheren.
