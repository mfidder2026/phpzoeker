# Changelog

## [Fase 0 + 1] — 15-10-2026

### Toegevoegd
- Laravel 13 + Filament 5-webapp op `/` met het vaste linkermenu uit §15.2 (groepen Werk, Aanbiedingen, Mensen & relaties, Inzicht, Beheer), inklapbare zijbalk, subitems per kandidaat, Fremen-huisstijl (#431858, Ubuntu Sans), licht en donker thema, globale zoekfunctie (Ctrl/⌘+K).
- Onderdelen uit latere fases staan al in het menu met "Volgt in fase x".
- Rollen Beheerder, Accountmanager, Reviewer, Kandidaat (spatie/laravel-permission) met policies; een kandidaat ziet alleen dashboard, matches, register en de eigen kandidaatpagina.
- 2FA (TOTP + herstelcodes), wachtwoordherstel, profielpagina.
- Instellingen in de database (spatie/laravel-settings): Algemeen, Mail, Matching, Register, Meldingen, CV, Privacy — met alle standaardwaarden uit §16.5.
- **Instellingen → AI-agent**: provider, versleutelde API-sleutel (alleen laatste 4 tekens zichtbaar), basis-URL, extra opties, modellen en max. tokens per taak, limieten, dagbudget, prijzen, webonderzoek, verwerkersovereenkomst en activatie.
- **Verbinding testen** (5 stappen, ook op niet-opgeslagen waarden) met foutmeldingen in gewone taal; resultaat bewaard als "Laatst getest".
- `RunTracker` + `ai_runs`: elke AI-aanroep loopt via een AI-taak met stappen, echte voortgang, tokens, kosten en fouten; annuleren tussen stappen.
- **AI-meter** in de kopbalk en onderaan de zijbalk, **voortgangspaneel** met stappenlijst, en de pagina **AI-activiteit** met kengetallen.
- Kandidaatbeheer met alle vaste waardes, afwezigheden, zoekprofiel en pasfoto; versleutelde geboortedatum en telefoon; seed van Mark en Sten.
- Master-CV: DOCX-import via `CvImporter` (geboortedatum/telefoon/e-mail eerst uit de tekst), gestructureerde editor, versies, goedkeuren (bevriest de versie), voorbeeldweergave.
- Fremen-CV-template met de placeholders uit Bijlage F (`php artisan cv:build-template`) en renderer naar Word; PDF via Gotenberg.
- Audit-log (spatie/laravel-activitylog), nooit met sleutels of geboortedatum.
- Docker Compose (app, worker, scheduler, PostgreSQL 16 + pgvector, Redis, Gotenberg, Mailpit) en GitHub Actions (Pint, Larastan level 8, Pest op SQLite én PostgreSQL, migraties terugdraaibaar).
- 73 tests (Pest), waaronder de acceptatiecriteria van fase 1 die zonder externe input te toetsen zijn.
