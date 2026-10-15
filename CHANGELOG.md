# Changelog

## [Fase 2 — ingest en opdrachten] — 15-10-2026

### Toegevoegd
- Connector-framework (§8.3) met `FeedConnector` (RSS/Atom), `MailboxAlertConnector` (IMAP) en `ManualConnector`; robots.txt-check en herkenbare user agent.
- Pipeline: alert-mails opsplitsen (`AlertMailSplitter`), exacte ontdubbeling op inhoud, relevantie-voorfilter, volledige openbare pagina ophalen, extractie (`AssignmentExtractor`, Bijlage B), normalisatie (uren, tarief, data, zzp, locatie), geocodering (PDOK), ontdubbeling op referentie en eindklant+titel, "mogelijk duplicaat".
- Levenscyclus: automatisch sluiten na deadline of 14 dagen niet gezien; negeren met reden; heropenen; opnieuw extraheren met behoud van gecorrigeerde velden.
- Schermen **Opdrachten** (filters, detail met samenvatting, eisen/wensen, originele tekst, bronnen, reisafstand per kandidaat, historie) en **Bronnen** (ToS-check verplicht, configuratie, run-log, Test run, Nu ophalen); instellingen **Bronnen & ingest**.
- Planning: elke minuut bronnen die aan de beurt zijn, elk uur sluiten, dagelijks 07:00 gezondheidscheck met melding aan de beheerder.
- Organisaties en contactpersonen worden bij extractie herkend; contactadressen alleen als ze letterlijk in de publicatie staan (R5).
- Bronnen: De Publieke Partner (RSS, staat uit tot de ToS-check), Alerts-mailbox (nog te configureren), Handmatig.
- `php artisan eval:extraction`; 41 nieuwe tests (121 totaal).

### Gewijzigd
- App-tijdzone Europe/Amsterdam.

## [Fase 1 — aanvulling] — 15-10-2026

### Gewijzigd
- Fremen-CV-template nu **afgeleid uit het aangeleverde CV van Mark** (exacte opmaak, logo, voettekst); pasfoto en metadata verwijderd. `cv:build-template` vraagt het bron-CV.
- Renderer ondersteunt meerdere profielalinea's, aanvullende secties in twee kolommen, eigen sectiekoppen, slotregel en projectsubtitels; waarden worden correct ge-escaped (een `&` gaf eerder een ongeldig document).
- Profielschema uitgebreid (D17); prompt `cv-importer.v2`.
- CV-import neemt pasfoto, telefoon en e-mail lokaal over (D19).

### Toegevoegd
- Master-profielen van Mark en Sten geïmporteerd (conceptversie 1, nog te reviewen en goed te keuren).
- De Publieke Partner (RSS-feed ICT & automatisering) en LinkedIn (alleen job-alerts) in het bronnenrapport.

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
