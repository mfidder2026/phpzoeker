# Koppeling met aanvragen@fremen.app (Microsoft 365)

PHP Zoeker leest het gedeelde postvak **aanvragen@fremen.app** via Microsoft Graph. Elke nieuwe mail wordt een
opdracht. Een job alert met meerdere opdrachten wordt opgesplitst, en een mail van een @fremen.app-adres geldt als
doorgestuurde aanvraag.

De app **verwijdert niets en verandert gelezen/ongelezen niet**. Verwerkte mails krijgen de categorie
`PHP Zoeker – verwerkt`, zodat jullie de mailbox gewoon kunnen blijven gebruiken.

De app krijgt alleen toegang tot deze ene mailbox. Daarvoor gebruiken we *RBAC for Applications* in Exchange Online,
en dus **niet** de tenant-brede API-machtiging in Entra ID.

## 1. App-registratie in Entra ID

1. Ga in het [Entra-beheercentrum](https://entra.microsoft.com) naar **Toepassingen → App-registraties → Nieuwe registratie**.
   - Naam: `PHP Zoeker`
   - Accounttypen: *Alleen accounts in deze organisatiemap*
   - Omleidings-URI: leeg laten
2. Noteer op de overzichtspagina:
   - **Toepassings-ID (client)** → in PHP Zoeker het veld *Client-ID*;
   - **Map-ID (tenant)** → in PHP Zoeker het veld *Tenant-ID*.
3. **Certificaten en geheimen → Nieuw clientgeheim**, bijvoorbeeld 24 maanden geldig. Kopieer direct de **Waarde**; die
   is later niet meer te zien. Zet een herinnering in de agenda om het geheim op tijd te vernieuwen.
4. Voeg onder *API-machtigingen* **géén** `Mail.Read` of `Mail.ReadWrite` toe. Dat zou toegang geven tot álle
   mailboxen. De rechten komen in stap 2 alleen voor aanvragen@fremen.app.
5. Ga naar **Toepassingen → Bedrijfstoepassingen → PHP Zoeker** en noteer de **Object-ID**. Let op: dat is de Object-ID
   van de bedrijfstoepassing, niet die van de app-registratie.

## 2. Rechten op alleen aanvragen@fremen.app (Exchange Online PowerShell)

```powershell
Connect-ExchangeOnline

# Vervang <Client-ID> en <Object-ID> door de waarden uit stap 1.
New-ServicePrincipal -AppId <Client-ID> -ObjectId <Object-ID> -DisplayName "PHP Zoeker"

New-ManagementScope -Name "PHP Zoeker - aanvragen" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'aanvragen@fremen.app'"

New-ManagementRoleAssignment -App <Object-ID> -Role "Application Mail.ReadWrite" -CustomResourceScope "PHP Zoeker - aanvragen"

# Controle: InScope moet True zijn voor aanvragen@fremen.app.
Test-ServicePrincipalAuthorization -Identity <Object-ID> -Resource aanvragen@fremen.app
```

`Mail.ReadWrite` is nodig om de categorie te kunnen zetten. De app gebruikt het nergens anders voor: hij verplaatst
en verwijdert niets en verstuurt niets. Het kan **30 minuten tot 2 uur** duren voordat de rechten werken.

## 3. Instellen in PHP Zoeker

1. Ga naar **Beheer → Bronnen → Aanvragen-mailbox (aanvragen@fremen.app)**.
2. Vul *Tenant-ID*, *Client-ID* en *Client-secret* in en klik **Opslaan**. Het secret wordt versleuteld opgeslagen
   en daarna nooit meer getoond.
3. Klik **Test run**. Je ziet de eerste nieuwe mails; er wordt nog niets opgeslagen of gemarkeerd.
4. Vul bij *Gebruiksvoorwaarden* de datum in (de notitie staat er al) en zet **Bron aan**.
5. De app kijkt elke 15 minuten in de mailbox. De eerste keer kijkt hij 14 dagen terug; dat is in te stellen.

## 4. Job alerts laten binnenkomen

Stel op de platforms waar jullie opdrachten zoeken e-mailalerts in met **aanvragen@fremen.app** als ontvanger,
bijvoorbeeld voor "Laravel", "PHP developer", "Python developer" en "full stack developer" (Bijlage A):

- LinkedIn Jobs: job alerts (de app scrapet LinkedIn zelf niet, regel R3);
- Striive, Freelance.nl, Hoofdkraan, IT-contracts, Indeed;
- intermediairs als HeadFirst, Yacht en Brainnet.

Kan een platform alleen naar een persoonlijk adres mailen? Stel dan in dat zulke mails automatisch worden
doorgestuurd naar aanvragen@fremen.app.
