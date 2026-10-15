# Koppeling met aanvragen@fremen.app (Microsoft 365)

PHP Zoeker leest het gedeelde postvak **aanvragen@fremen.app**. Elke nieuwe mail wordt een opdracht. Een job alert
met meerdere opdrachten wordt opgesplitst, en een mail van een @fremen.app-adres geldt als doorgestuurde aanvraag.

De app **verwijdert niets en verandert gelezen/ongelezen niet**. Verwerkte mails krijgen de categorie
`PHP Zoeker – verwerkt`.

## Verbinden: één keer inloggen

1. Ga naar **Beheer → Bronnen → Aanvragen-mailbox (aanvragen@fremen.app)**.
2. Klik rechtsboven op **Inloggen bij Microsoft**. Je ziet een code; die staat ook op je klembord.
3. Klik op **Naar de inlogpagina van Microsoft**, vul de code in en log in met een account dat toegang heeft tot
   aanvragen@fremen.app.
4. Microsoft vraagt toestemming voor *Microsoft Graph Command Line Tools* om je profiel te lezen en mail te lezen en
   te markeren in mailboxen waar je toegang toe hebt (`Mail.ReadWrite.Shared`). Klik **Accepteren**.
5. Het venster in PHP Zoeker ziet vanzelf dat je bent ingelogd en toont "Verbonden als …".
6. Klik **Test run**: je ziet de eerste nieuwe mails. Er wordt nog niets opgeslagen of gemarkeerd.
7. Vul bij *Gebruiksvoorwaarden* de datum in (de notitie staat er al) en zet **Bron aan**.

Daarna kijkt de app elke 15 minuten in de mailbox. De eerste keer kijkt hij 14 dagen terug; dat is in te stellen.
De app vernieuwt de verbinding zelf. Opnieuw inloggen is alleen nodig als:
- het wachtwoord van het account verandert;
- de sessies van het account worden ingetrokken;
- de app 90 dagen niet heeft gedraaid.

De bron krijgt dan een waarschuwing.

### Goed om te weten

- De app gebruikt de **rechten van het account waarmee je inlogt**. Technisch kan die verbinding alle mailboxen lezen
  waar dat account bij kan, maar PHP Zoeker vraagt alleen de mailbox op die bij de bron is ingesteld.
- *Microsoft Graph Command Line Tools* is een app van Microsoft zelf; Microsoft Graph PowerShell gebruikt dezelfde.
  Daarom is geen eigen app-registratie nodig. Blokkeert jullie tenant die app (bijvoorbeeld via voorwaardelijke
  toegang of omdat gebruikers geen toestemming mogen geven), dan kan een beheerder bij stap 4 *Toestemming namens uw
  organisatie* aanvinken, of de route hieronder gebruiken.
- Het vernieuwingstoken staat versleuteld in de database en wordt nooit getoond. Via **Verbinding verbreken** wist
  PHP Zoeker de verbinding en zet hij de bron uit. Intrekken kan ook in Microsoft: *Mijn account → Apps* of de
  aanmeldsessies.

## Alternatief (geavanceerd): eigen app-registratie

Kies bij de bron onder *Aanmelden* de optie **Eigen app-registratie** en vul tenant-ID, client-ID en client-secret in.
Geef die app-registratie in Exchange Online alleen rechten op deze mailbox (*RBAC for Applications*):

```powershell
Connect-ExchangeOnline
New-ServicePrincipal -AppId <Client-ID> -ObjectId <Object-ID bedrijfstoepassing> -DisplayName "PHP Zoeker"
New-ManagementScope -Name "PHP Zoeker - aanvragen" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'aanvragen@fremen.app'"
New-ManagementRoleAssignment -App <Object-ID> -Role "Application Mail.ReadWrite" -CustomResourceScope "PHP Zoeker - aanvragen"
```

## Job alerts laten binnenkomen

Stel op de platforms waar jullie opdrachten zoeken e-mailalerts in met **aanvragen@fremen.app** als ontvanger,
bijvoorbeeld voor "Laravel", "PHP developer", "Python developer" en "full stack developer":

- LinkedIn Jobs: job alerts (de app scrapet LinkedIn zelf niet, regel R3);
- Striive, Freelance.nl, Hoofdkraan, IT-contracts, Indeed;
- intermediairs als HeadFirst, Yacht en Brainnet.
