# Bronnenrapport (fase 0) — CONCEPT, nog te onderzoeken

> Status: **nog niet uitgevoerd.** Per bron moet nog worden vastgesteld of er een API/feed of alert-functie is, wat de gebruiksvoorwaarden zeggen en of er een login nodig is (§22, fase 0). Deze tabel is het startpunt uit §8.2; de kolommen *Bevinding* en *Besluit* worden ingevuld na onderzoek en review door Fremen.

| Prio | Bron | Voorgestelde methode | API/feed? | Alerts? | Login nodig? | ToS-samenvatting | Bevinding | Besluit |
|------|------|----------------------|-----------|---------|--------------|------------------|-----------|---------|
| 1 | Striive (HeadFirst) | Leveranciersaccount + e-mailnotificaties | ? | ? | ja (leverancier) | ? | | |
| 1 | Freelance.nl | Alerts → mailbox | ? | ? | ? | ? | | |
| 1 | Hoofdkraan.nl | Alerts of feed | ? | ? | ? | ? | | |
| 1 | IT-contracts.nl | Alerts of feed | ? | ? | ? | ? | | |
| 1 | Freelancer.nl | Alerts → mailbox | ? | ? | ? | ? | | |
| 1 | LinkedIn Jobs (op verzoek Fremen, 15-10-2026) | **Alleen job-alert e-mails** naar de alerts-mailbox; de volledige opdrachttekst handmatig of via de link als die openbaar is | geen openbare API voor vacatures | ja (job alerts per zoekopdracht) | ja | Gebruiksvoorwaarden verbieden scraping en geautomatiseerde toegang; daarom geen connector op de site zelf (R3) | Fremen stelt job alerts in (bijv. "Laravel", "PHP developer", Nederland) met afzender naar de alerts-mailbox | Go via mailbox |
| 1 | **De Publieke Partner** (depubliekepartner.nl, op verzoek Fremen, 15-10-2026) | **RSS-feed** van de categorie ICT & automatisering: `https://depubliekepartner.nl/category/zzp-opdrachten-overheid/ict-en-automatisering/feed/` | ja: WordPress-RSS (gecontroleerd 15-10-2026: 10 items, vandaag bijgewerkt) | onbekend | nee | robots.txt: `User-agent: *` → `Allow: /` (alleen `/wp-admin/` uitgesloten). Gebruiksvoorwaarden nog lezen | Overheidsopdrachten (zzp/detachering); feed bevat ook niet-ontwikkelrollen → relevantiefilter (FR-O4) nodig | Voorstel: go, `FeedConnector` in fase 2, interval 60 min, na ToS-check |
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
