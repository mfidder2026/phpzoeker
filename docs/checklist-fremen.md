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
- [ ] App-registratie "PHP Zoeker" in Entra ID (client-ID, tenant-ID, client-secret).
- [ ] Rechten via Exchange RBAC for Applications: rol `Application Mail.ReadWrite`, bereik alleen aanvragen@fremen.app.
- [ ] Gegevens invullen bij **Bronnen → Aanvragen-mailbox**, Test run, ToS-datum invullen en de bron aanzetten.
- [ ] Job alerts instellen met aanvragen@fremen.app als ontvanger (LinkedIn, Striive, Freelance.nl, Hoofdkraan, IT-contracts, intermediairs).
- [ ] **De Publieke Partner**: samenvatting van de voorwaarden staat in `docs/bronnen.md`. Besluit nemen en in **Bronnen → De Publieke Partner** de ToS-check invullen en de bron aanzetten.
- [ ] ±20 echte job-alert-mails (geanonimiseerd) aanleveren voor de evaluatieset (`eval:extraction`).

## Mail versturen
- [ ] Afzendermailbox kiezen (`michel@fremen.app` of `sales@fremen.app`) en de handtekening invullen in **Instellingen → Mail**.
- [ ] Bij Microsoft 365: app-registratie met `Mail.Send` (+ `Mail.ReadWrite`) beperkt tot de afzendermailbox.

## Bronnen
- [ ] Bronnenrapport (`docs/bronnen.md`) reviewen en per bron een besluit nemen (go/no-go + methode).
