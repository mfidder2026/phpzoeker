# Checklist voor Fremen (fase 0)

Handwerk dat Fremen zelf moet doen; de app ondersteunt het.

## AI-agent
- [x] Verwerkersovereenkomst bevestigd en profiel actief (15-10-2026).
- [ ] Prijzen per 1 mln. input-/outputtokens invullen, zodat de kostenschatting en het dagbudget kloppen.
- [ ] Een test-URL voor webonderzoek invullen (openbare pagina op een toegestaan domein) en de verbinding testen.
- [ ] De API-sleutel die bij de start in de chat is gedeeld, roteren en de nieuwe sleutel via het instellingenscherm invoeren.

## Kandidaten
- [x] Word-CV's van Mark en Sten aangeleverd en geïmporteerd (conceptversie 1).
- [ ] Per kandidaat het conceptprofiel reviewen (Kandidaten → Master-profiel → Bekijken/Bewerken) en goedkeuren.
- [ ] Bevestigen: beschikbaarheid "per direct", tarieven excl. btw, vakantie 18 t/m 31 december 2026 (§23 punten 6, 7, 12).
- [ ] Accounts voor Mark (Kandidaat + Reviewer) en Sten (Kandidaat) aanmaken via *Gebruikers*.

## Aanvragen-mailbox (§8.5)
De app leest het gedeelde postvak **aanvragen@fremen.app** (Microsoft 365). Stappen: [`docs/m365-aanvragen-mailbox.md`](m365-aanvragen-mailbox.md).
- [ ] **Bronnen → Aanvragen-mailbox → Inloggen bij Microsoft**: één keer inloggen met een account dat toegang heeft tot aanvragen@fremen.app.
- [ ] Test run, ToS-datum invullen en de bron aanzetten.
- [ ] Job alerts instellen met aanvragen@fremen.app als ontvanger (LinkedIn, Striive, Freelance.nl, Hoofdkraan, IT-contracts, intermediairs).
- [ ] **De Publieke Partner**: samenvatting van de voorwaarden staat in `docs/bronnen.md`. Besluit nemen en in **Bronnen → De Publieke Partner** de ToS-check invullen en de bron aanzetten.
- [ ] ±20 echte job-alert-mails (geanonimiseerd) aanleveren voor de evaluatieset (`eval:extraction`).

## Mail versturen
- [ ] Afzendermailbox kiezen (`michel@fremen.app` of `sales@fremen.app`) en de handtekening invullen in **Instellingen → Mail**.
- [ ] Bij Microsoft 365: app-registratie met `Mail.Send` (+ `Mail.ReadWrite`) beperkt tot de afzendermailbox.

## Bronnen
- [ ] Bronnenrapport (`docs/bronnen.md`) reviewen en per bron een besluit nemen (go/no-go + methode).

## Matching en meldingen (fase 3)
- [ ] **Evaluatieset**: 30–50 echte opdrachten (geanonimiseerd), per kandidaat gelabeld met *aanbieden*, *twijfel* of *niet*. Formaat: zie `tests/Fixtures/matching`; plaatsen in `storage/app/private/eval/matching` en draaien met `php artisan eval:matching --map=senior=mark-van-der-zande --map=medior=sten-nierop`. Acceptatie: precisie sterke match ≥ 70%.
- [ ] **Instellingen → Meldingen**: ontvangers van de digest controleren (nu michel@fremen.app) en kiezen of directe meldingen ook per mail gaan.
- [ ] **Instellingen → Matching**: gewichten, drempels en technologie-aliassen nalopen.
- [ ] Prijzen per 1 mln. tokens invullen in **Instellingen → AI-agent**, anders werkt de budgetbewaking met € 0.
- [ ] De eerste weken matches beoordelen met 👍/👎; daarmee worden de gewichten bijgesteld.

## Aanbieden (fase 4)
- [ ] **OverheidZZP**: samenvatting van robots.txt en voorwaarden staat in `docs/bronnen.md`. Besluit nemen en in **Bronnen → OverheidZZP** de ToS-check invullen en de bron aanzetten.
- [ ] **Verzenden via Microsoft 365**: Instellingen → Mail → mailprovider *Microsoft 365*, afzendermailbox invullen, **Inloggen bij Microsoft** en **Testmail sturen** (zie `docs/m365-aanvragen-mailbox.md`).
- [ ] **Instellingen → Mail**: afzendermailbox, naam en handtekening (HTML en tekst) invullen; standaard-CC controleren (nu mark@fremen.app en michel@fremen.app).
- [ ] **Instellingen → CV**: Fremen-contact (naam, e-mail, telefoon) voor de contactregel in het CV.
- [ ] **Relaties**: website en standaard reactietermijn per vaste intermediair (bijv. HeadFirst) invullen.
- [ ] Akkoord geven op een bibliotheek voor Outlook-**.msg** (nu alleen .eml).
- [ ] De eerste aanbieding samen doorlopen: CV-review, grounding-rapport en mail controleren vóór verzending.
