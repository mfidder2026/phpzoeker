# Changelog

## [Fase 5 — rapportage en verbeteren] — 15-10-2026

### Toegevoegd
- **Inloggen met Microsoft** op de inlogpagina; bij een beheerder koppelt dezelfde inlog ook verzenden via Microsoft 365 en de aanvragen-mailbox.
- **Bronnencatalogus (§8.2) in de app**: alle 33 alert-bronnen (type *Job alert → aanvragen-mailbox*, met waar je de alert instelt), drie nieuwe openbare opdrachtfeeds (Quest4, Morgan Black, B-Hunted; robots.txt gecontroleerd, aan na de ToS-check) en TenderNed/Adzuna (connector nog te bouwen). Knop **Nu zoeken** op het dashboard en bij Bronnen haalt alle actieve bronnen direct op.
- **Reply-tracking**: reacties op aanbiedingsmails worden elke 15 minuten in de afzendermailbox (Microsoft 365) gevonden, komen in de tijdlijn met afzender en fragment, tellen mee voor de reactietijd en geven een melding; het register stelt de statuswijziging voor.
- **Rapportages**: funnel (match → shortlist → aangeboden → reactie → intake → geplaatst), doorlooptijden, matches per kandidaat met feedback, nieuwe opdrachten per bron per week, AI-kosten per dag en per agent, en **gemiste kansen** (aangeboden terwijl de app laag scoorde).
- **Gewichtsvoorstel** op basis van 👍/👎 in Instellingen → Matching, met "Voorstel overnemen" (herberekent alle scores zonder AI).
- **Import van eerdere aanbiedingen** uit CSV in het register en via `php artisan offers:import`.
- **Fallbackprofiel** voor de AI-agent en een profielkeuze in Instellingen → AI-agent.
- **Dagelijkse AI-verbindingstest** (06:45) met melding; **bewaartermijnen** elke nacht (03:00).
- **Teams-meldingen** via een Workflows-webhook.
- **Engelse CV-koppen** voor Engelstalige opdrachten.
- 22 nieuwe tests (244 totaal).

### Gewijzigd
- Werkdagen tussen twee momenten worden in Europe/Amsterdam geteld (een tijdstip in UTC telde soms een dag te veel).
## [Fase 4 — aanbieden, CV, mail en aanbiedingsregister] — 15-10-2026

### Toegevoegd
- **Aanbieden-wizard** (§11.2) vanaf een match: 1 controle (route, kanaal, tarief, privacy-opties CV, waarschuwingen), 2 CV, 3 mail, 4 versturen. Statusmachine met alle overgangen uit §11.1; elke overgang in de tijdlijn en het audit-log.
- **Blokkade op dubbele aanbiedingen** (R7): één actieve aanbieding per kandidaat en dedupe-groep, afgedwongen door een partiële unieke index; melding "… is al aangeboden via … op …" met link. Waarschuwing bij een vergelijkbare aanbieding bij dezelfde eindklant (90 dagen).
- **CV herschrijven** (`CvTailor`, prompt `cv-tailor.v1`) met voortgangspaneel, optioneel **website-onderzoek** (`WebResearcher`), **grounding-check** (vaste velden terugzetten, deterministische checks en `CvGroundingJudge`); goedkeuren pas als alles groen is. Verschilweergave met het master-profiel, bewerken per sectie (nieuwe versie), Word en PDF in de Fremen-template; PDF via Word op Windows of Gotenberg.
- **Aanbiedingsmail**: `OfferMailWriter` schrijft alleen introductie en bullets; onderwerp, aanhef, beschikbaarheid (met afwezigheden in de looptijd), tarief, woonplaats en handtekening komen uit data en Blade. Ontvangers alleen uit gevalideerde bronnen of handmatig bevestigd (R5), standaard-CC Mark en Michel, BCC-archief. Goedkeuren, versturen met bevestiging, idempotent en nooit zonder menselijke goedkeuring (R1).
- **Akkoord van de kandidaat** met een signed link (72 uur) of mondeling; **platformindiening** met indieningspakket (CV, motivatie, antwoorden per eis), alleen een interne mail aan Mark en Michel, en "verzonden" pas na het externe referentienummer.
- **Aanbiedingsregister** (§11.5): overzicht met kengetallen, snelfilters en alle filters uit FR-AR2, export naar CSV (Excel), detail met *Overzicht*, *Zo zag de aanbieding eruit*, *Tijdlijn*, *Opdracht (toen)* en *Notities*; acties status wijzigen (met afwijzingsreden), opvolgmail, notitie, reactietermijn aanpassen, reactie en intake vastleggen, intrekken.
- **Snapshot** bij verzending (§11.7): mail met kopregels, HTML, tekst en .eml, kopie van de bijlagen, aanbiedingsgegevens, eisen-matrix, opdracht van toen en herkomst; SHA-256 en badge "Ongewijzigd sinds verzending"; database-trigger tegen wijzigen; afgeschermde weergave (sandbox, CSP); downloads via signed URL.
- **Verwachte reactie** in werkdagen met Nederlandse feestdagen (termijn van de aanbieding, relatie, bron of globaal; later van verzending en deadline; 17:00), indicator groen/oranje/rood, menubadge, elk uur een melding bij een verlopen reactie aan de aanbieder en Michel, opvolgmail in dezelfde conversatie en een nieuwe verwachte reactie (+3 werkdagen). Voorstel "geen reactie" na 2 opvolgingen en 10 werkdagen.
- **Handmatig registreren** (§11.8) met import van een .eml (kopregels, datum en bijlagen) en upload van het CV; minimale opdracht in één stap.
- **Relaties** (§11.9): organisaties met contactpersonen, standaard en gemeten reactietijd (nachtelijk berekend, voorstel bij > 2 werkdagen verschil), alle aanbiedingen per relatie, samenvoegen.
- **Verzenden via Microsoft 365**: eenmalige Microsoft-login op Instellingen → Mail, verzending vanuit de afzendermailbox (staat in Verzonden items), opvolgmail als antwoord in dezelfde conversatie, testmail, verbinding verbreken. SMTP blijft als alternatief.
- **Bron OverheidZZP** via een nieuwe connector voor openbare overzichtspagina's (`HtmlListingConnector`; robots.txt-check, linkpatroon, kaarttekst), staat uit tot de ToS-check.
- Shortlist-kanban toont nu ook de aanbiedingen; dashboard met aanbiedingen deze week en reacties verlopen; opdracht-detail toont "… is al aangeboden via …".
- 42 nieuwe tests (222 totaal).
## [Fase 3 — matching en meldingen] — 15-10-2026

### Toegevoegd
- Matching per opdracht × kandidaat (§10): voorselectie zonder AI, harde filters (status, beschikbaarheid, uren, contractvorm, deadline, afwezigheid, reistijd) met de reden erbij, AI-beoordeling per eis en wens met bewijs uit het master-profiel (`MatchAssessor`, Bijlage C, prompt `match-assessor.v2`), score 0–100 met opbouw per component en labels sterk / mogelijk / zwak / afgevallen.
- Eerlijk rekenen met jaren: jaren per technologie worden uit de periodes in het profiel berekend; een tekort maakt de eis "voldoet niet" (D30). Knock-out overrulen kan alleen met reden.
- Automatisch matchen bij een nieuwe of bijgewerkte opdracht; herberekenen bij een wijziging van kandidaatwaardes, zoekprofiel, afwezigheid of master-profiel, en elke nacht om 02:00. Gewichten wijzigen herberekent alleen de score, zonder AI.
- Scherm **Matches** (filters op kandidaat, label, status, advies, knock-out, deadline) met detail: score-opbouw, harde filters, eisen/wensen-matrix, sterke punten en risico's, waarschuwingen (o.a. "Tarief boven maximum"), feedback 👍/👎 met reden, shortlist, afwijzen, opnieuw beoordelen. Een kandidaat ziet alleen de eigen matches en kan interesse aangeven.
- **Shortlist**-kanban (Nieuw → Shortlist → Afgewezen; de kolommen voor aanbiedingen volgen in fase 4).
- Opdracht-detail: tabblad *Matches*; opdrachtenlijst: beste score per kandidaat, filters "Match met kandidaat" en "Score 60 of hoger", knop **Matchen**.
- Dashboard: sterke matches vandaag, shortlist-deadlines binnen 24 uur, "Actie nodig" met sterke matches.
- Meldingen: directe melding bij een sterke match (urgent bij deadline < 48 uur), deadline-herinnering voor de shortlist (< 24 uur), dagelijkse digest op werkdagen om 08:00, budgetwaarschuwing bij 80% en 100%.
- AI-budget: matching pauzeert eerder dan extractie (D32).
- Instellingen: technologie-aliassen, reserve voor extractie, maximum aantal beoordelingen per herberekening, mail bij directe meldingen, urenvensters voor urgent en herinnering.
- `php artisan eval:matching` met een voorbeeld-evaluatieset; 48 nieuwe tests (180 totaal).
## [Fase 2 — ingest en opdrachten] — 15-10-2026

### Toegevoegd
- Connector-framework (§8.3) met `FeedConnector` (RSS/Atom), `MailboxAlertConnector` (IMAP) en `ManualConnector`; robots.txt-check en herkenbare user agent.
- Pipeline: alert-mails opsplitsen (`AlertMailSplitter`), exacte ontdubbeling op inhoud, relevantie-voorfilter, volledige openbare pagina ophalen, extractie (`AssignmentExtractor`, Bijlage B), normalisatie (uren, tarief, data, zzp, locatie), geocodering (PDOK), ontdubbeling op referentie en eindklant+titel, "mogelijk duplicaat".
- Levenscyclus: automatisch sluiten na deadline of 14 dagen niet gezien; negeren met reden; heropenen; opnieuw extraheren met behoud van gecorrigeerde velden.
- Schermen **Opdrachten** (filters, detail met samenvatting, eisen/wensen, originele tekst, bronnen, reisafstand per kandidaat, historie) en **Bronnen** (ToS-check verplicht, configuratie, run-log, Test run, Nu ophalen); instellingen **Bronnen & ingest**.
- Planning: elke minuut bronnen die aan de beurt zijn, elk uur sluiten, dagelijks 07:00 gezondheidscheck met melding aan de beheerder.
- Organisaties en contactpersonen worden bij extractie herkend; contactadressen alleen als ze letterlijk in de publicatie staan (R5).
- Bronnen: De Publieke Partner (RSS, staat uit tot de ToS-check), Alerts-mailbox (nog te configureren), Handmatig.
- Koppeling met het gedeelde postvak **aanvragen@fremen.app** via Microsoft Graph met knop **Inloggen bij Microsoft** (eenmalig inloggen met een code, geen app-registratie nodig): categorie in plaats van gelezen markeren, tokens versleuteld en nooit getoond, verbinding verbreken; zie `docs/m365-aanvragen-mailbox.md`.
- `php artisan eval:extraction`; 52 nieuwe tests (132 totaal).

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
