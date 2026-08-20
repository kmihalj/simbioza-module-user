# Modul Simbioza User

Osobno praćenje sadržaja, obavijesti u aplikaciji i opcionalna dostava e-poštom za aplikaciju znanja Simbioza.

English documentation: [README.md](README.md)

## Ovisnosti

Obavezni Composer paketi koji moraju biti uključeni prije ovog modula:

- `aaieduhr/heartphrame-framework`
- `aaieduhr/heartphrame-module-auth`
- `aaieduhr/heartphrame-module-orm`
- `aaieduhr/heartphrame-module-notification`
- `aaieduhr/heartphrame-module-workspace`

Opcionalne integracije otkrivaju se tijekom rada: `heartphrame-module-api`, `heartphrame-module-audit`, `heartphrame-module-backup`, `heartphrame-module-calendar`, `heartphrame-module-comment`, `heartphrame-module-email`, `heartphrame-module-task` i `heartphrame-module-theme`.

## Mogućnosti

- praćenje pojedinačne Workspace stranice, cijelog područja, kalendara ili cijele ugrađene liste zadataka;
- automatsko usklađivanje novih i postojećih Calendar pretplata, uz mogućnost isključivanja i ponovnog uključivanja obavijesti bez prekida pretplate;
- izdvojena akcija praćenja s ikonom i tekstom, stilizirana svijetlim/tamnim Theme tokenima akcija dokumenta;
- ACL-sigurna tablica u profilu s pretragom, filtrima stanja, prekidačem praćenja po stavci i kompaktnim ikon-gumbima načina dostave bez osvježavanja stranice;
- izbor neposredne e-pošte, dnevnog sažetka, samo važnih promjena ili samo obavijesti u aplikaciji;
- opcionalno isključivanje obavijesti o vlastitim promjenama;
- uklanjanje duplikata kod istodobnog praćenja područja i stranice te kod brzih istovrsnih izmjena;
- promjena kalendara ili liste zadataka ugrađene u praćenu stranicu računa se kao promjena te stranice;
- kalendarske obavijesti navode radnju, događaj i termin, uključujući stari i novi termin kada je raspored promijenjen;
- ponovna ACL provjera prije dostave i pri svakom prikazu ili otvaranju postojeće obavijesti;
- API pristup vlastitim praćenjima kada je API modul uključen;
- backup trajnih praćenja i postavki u cjelini Korisnici te scoped praćenja u backupu područja;
- audit zapis promjena praćenja i postavki kada je Audit modul uključen.

Backup sitea i cjeline Korisnici vraća globalne osobne postavke, a backup
područja vraća samo praćenja i načine dostave povezane s tim područjem.
Privremeni redovi dnevnog sažetka namjerno se ne spremaju u backup.

## Brzi početak

```bash
composer require aaieduhr/simbioza-module-user:dev-main
php vendor/bin/hph simbioza-user:install-migration
php vendor/bin/hph orm-migrate:up
```

Uključite `aaieduhr/simbioza-module-user` nakon obaveznih modula. Auth profil osnovne podatke računa spaja sa sigurnosnim accordionom, a **Osobne postavke** prikazuje u trajno otvorenoj kartici. **Postavke obavijesti** također su uvijek otvorene, dok se tablica pretražuje i filtrira u accordionu **Praćeni sadržaj**. Gumbi stranice, područja i zadatka pojavljuju se u prikazima njihovih modula, a Calendar pretplate sinkroniziraju se s jedinstvenim popisom.

Dospjele dnevne sažetke pokreće worker ili cron:

```bash
php vendor/bin/hph simbioza-user:dispatch --limit=500
```

## Dokumentacija

- [Hrvatske upute](docs/index_hr.md)
- [Korištenje praćenja](docs/usage_hr.md)
- [API](docs/api_hr.md)
- [Integracija i sigurnost](docs/integration_hr.md)

## Razvojne provjere

```bash
vendor/bin/phpcs -p
vendor/bin/phpstan --memory-limit=1024M
vendor/bin/phpstan analyze -c phpstan-dev.neon --memory-limit=1024M
vendor/bin/phpunit
```

Licenca: EUPL-1.2.
