# Bronnenrapport (fase 0) — CONCEPT, nog te onderzoeken

> Status (15-10-2026): **alle bronnen staan in de app** (Bronnen). Feeds van Quest4, Morgan Black en B-Hunted gevonden en robots.txt gecontroleerd; de overige bronnen lopen via job alerts naar de aanvragen-mailbox. Oorspronkelijke status: nog niet uitgevoerd. Per bron moet nog worden vastgesteld of er een API/feed of alert-functie is, wat de gebruiksvoorwaarden zeggen en of er een login nodig is (§22, fase 0). Deze tabel is het startpunt uit §8.2; de kolommen *Bevinding* en *Besluit* worden ingevuld na onderzoek en review door Fremen.

| Prio | Bron | Voorgestelde methode | API/feed? | Alerts? | Login nodig? | ToS-samenvatting | Bevinding | Besluit |
|------|------|----------------------|-----------|---------|--------------|------------------|-----------|---------|
| 1 | Striive (HeadFirst) | Leveranciersaccount + e-mailnotificaties | ? | ? | ja (leverancier) | ? | | |
| 1 | Freelance.nl | Alerts → mailbox | ? | ? | ? | ? | | |
| 1 | Hoofdkraan.nl | Alerts of feed | ? | ? | ? | ? | | |
| 1 | IT-contracts.nl | Alerts of feed | ? | ? | ? | ? | | |
| 1 | Freelancer.nl | Alerts → mailbox | ? | ? | ? | ? | | |
| 1 | LinkedIn Jobs (op verzoek Fremen, 15-10-2026) | **Alleen job-alert e-mails** naar de alerts-mailbox; de volledige opdrachttekst handmatig of via de link als die openbaar is | geen openbare API voor vacatures | ja (job alerts per zoekopdracht) | ja | Gebruiksvoorwaarden verbieden scraping en geautomatiseerde toegang; daarom geen connector op de site zelf (R3) | Fremen stelt job alerts in (bijv. "Laravel", "PHP developer", Nederland) met afzender naar de alerts-mailbox | Go via mailbox |
| 1 | **De Publieke Partner** (depubliekepartner.nl, op verzoek Fremen, 15-10-2026) | **RSS-feed** van de categorie ICT & automatisering: `https://depubliekepartner.nl/category/zzp-opdrachten-overheid/ict-en-automatisering/feed/` | ja: WordPress-RSS (gecontroleerd 15-10-2026: 10 items, vandaag bijgewerkt) | onbekend | nee | robots.txt: `User-agent: *` → `Allow: /` (alleen `/wp-admin/` uitgesloten). Algemene voorwaarden (v1.0, 03-03-2024, gelezen 15-10-2026): gaan over bemiddeling, aansprakelijkheid en privacy; **geen bepaling over geautomatiseerde toegang, scraping of hergebruik van vacatureteksten**. | Feed bevat alleen titels; de app haalt per relevante titel de openbare opdrachtpagina op (robots.txt toegestaan). Veel niet-ontwikkelrollen → voorfilter op titel. Testrun 15-10-2026: 10 items. Echte extractie van 1 opdracht (Cloud/Platform Engineer, Vlaardingen) correct. | Gebouwd (fase 2), staat **uit** tot Fremen de ToS-check invult in Bronnen |
| 1 | **OverheidZZP** (overheidzzp.nl, op verzoek Fremen, 15-10-2026) | **Openbare overzichtspagina**: ICT & informatievoorziening, nieuwste eerst (`/vacatures/?vacancy_tag[]=ict-informatievoorziening&sort=newest`) en zoeken op "developer"; daarna per opdracht de openbare pagina | nee: WordPress-feeds staan uit en `*/feed/` is uitgesloten in robots.txt; de vacature-sitemap is verouderd (201 van ±790 opdrachten) | nee | nee | robots.txt (15-10-2026): `/vacatures/` en de zoekpagina toegestaan; `*/feed/`, `/*page/` en `/search/` uitgesloten (die gebruikt de app niet); crawl-delay alleen voor AI-crawlers. Disclaimer: geen bepaling over geautomatiseerde toegang; de pagina Algemene voorwaarden is leeg. Opdrachten openbaar zonder inloggen. | Testrun 15-10-2026: 23 publicaties (o.a. Fullstack developer bij Logius). Kaart bevat sluitdatum, opdrachtgever, periode, regio, uren en contractvorm. Veel niet-ontwikkelrollen → voorfilter op titel. | Gebouwd (`HtmlListingConnector`), staat **uit** tot Fremen de ToS-check invult in Bronnen |
| 1 | Indeed NL | Job-alert e-mails | ? | ? | ? | ? | | |
| 2 | OverheidZZP.nl | Alerts → mailbox | ? | ? | ? | ? | | |
| 2 | Bij Oranje | Alerts → mailbox | ? | ? | ? | ? | | |
| 2 | TenMonks | Alerts → mailbox | ? | ? | ? | ? | | |
| 2 | Quest4 | Alerts → mailbox | ? | ? | ? | ? | | |
| 2 | HeadFirst | Alerts → mailbox | ? | ? | ? | ? | | |
| 2 | Yacht | Alerts → mailbox | ? | ? | ? | ? | | |
| 2 | Brainnet | Alerts → mailbox | ? | ? | ? | ? | | |
| 2 | TenderNed | Open data / API (alleen ICT-CPV) | ? | n.v.t. | nee | ? | | |
| 3 | Lajos.nl | Alerts of feed | ? | ? | ? | ? | | |
| 3 | Adzuna | Officiële API | ? | n.v.t. | API-key | ? | | |
