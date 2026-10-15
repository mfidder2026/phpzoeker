# Checklist voor Fremen (fase 0)

Handwerk dat Fremen zelf moet doen; de app ondersteunt het.

## AI-agent
- [ ] Verwerkersovereenkomst met de AI-provider (nu Ollama Cloud) regelen en in **Instellingen → AI-agent** bevestigen; daarna het profiel op *Actief* zetten.
- [ ] Prijzen per 1 mln. input-/outputtokens invullen, zodat de kostenschatting en het dagbudget kloppen.
- [ ] Een test-URL voor webonderzoek invullen (openbare pagina op een toegestaan domein) en de verbinding testen.
- [ ] De API-sleutel die bij de start in de chat is gedeeld, roteren en de nieuwe sleutel via het instellingenscherm invoeren.

## Kandidaten
- [ ] Het Word-CV van Mark (`Fremen-CV-…-NL_v2_5.docx`) en van Sten (`Sten_Nierop_CV.docx`) aanleveren; daarmee wordt de CV-template afgestemd en de import getest.
- [ ] Per kandidaat het CV importeren, het conceptprofiel reviewen en goedkeuren.
- [ ] Bevestigen: beschikbaarheid "per direct", tarieven excl. btw, vakantie 18 t/m 31 december 2026 (§23 punten 6, 7, 12).
- [ ] Accounts voor Mark (Kandidaat + Reviewer) en Sten (Kandidaat) aanmaken via *Gebruikers*.

## Alerts-mailbox (§8.5)
- [ ] Gedeelde mailbox aanmaken, bijvoorbeeld `opdrachten@fremen.app`.
- [ ] Op elke bron uit §8.2 alerts instellen met de zoekprofielen uit Bijlage A.
- [ ] Per afzender een map maken of laten routeren op afzenderdomein.
- [ ] Leesrechten voor de app op alleen deze mailbox (Microsoft 365: app-registratie met `Mail.Read`, beperkt via RBAC for Applications).

## Mail versturen
- [ ] Afzendermailbox kiezen (`michel@fremen.app` of `sales@fremen.app`) en de handtekening invullen in **Instellingen → Mail**.
- [ ] Bij Microsoft 365: app-registratie met `Mail.Send` (+ `Mail.ReadWrite`) beperkt tot de afzendermailbox.

## Bronnen
- [ ] Bronnenrapport (`docs/bronnen.md`) reviewen en per bron een besluit nemen (go/no-go + methode).
