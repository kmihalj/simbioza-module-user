# Upute za Simbioza User

Simbioza User je aplikacijski koordinator. Ne duplicira poslovnu logiku Workspace, Calendar, Comment, Task, Notification ni E-mail modula. Vlasnički moduli objavljuju neutralne događaje, a ovaj modul izrađuje osobna područja, pronalazi osobna praćenja, primjenjuje aktualni ACL i bira kanal dostave.

## Redoslijed čitanja

1. [Korištenje praćenja](usage_hr.md) za administratore i krajnje korisnike.
2. [API](api_hr.md) za vlasnike API ključeva i integratore.
3. [Integracija i sigurnost](integration_hr.md) za developere i administratore sustava.

Engleska dokumentacija dostupna je u [index_en.md](index_en.md).

## Vlasništvo podataka

Modul posjeduje šest aktivnih tablica i jednu zadržanu radi kompatibilnosti:

- `simbioza_user_preferences`: jedno zadano pravilo dostave po korisniku;
- `simbioza_user_follows`: trajna polimorfna praćenja;
- `simbioza_user_follow_exclusions`: izričita isključenja automatskog praćenja, trenutačno za pretplaćene kalendare;
- `simbioza_user_pending_deliveries`: privremeni red dnevnog sažetka;
- `simbioza_user_settings`: administratorske postavke izrade;
- `simbioza_user_personal_workspaces`: stabilno mapiranje korisnika na njegovo osobno Workspace područje;
- `simbioza_user_personal_workspace_policies`: zastarjela tablica pravila, zadržana radi kompatibilnosti već objavljenih migracija; aktualni servis ne primjenjuje njezine retke.

Izradu osobnog područja određuju dvije globalne postavke u **Postavke → Osobna
područja**. Automatska izrada pri uspješnoj prijavi zadano je uključena. Ako je
isključena, administrator može omogućiti korisniku samostalnu izradu kroz
**Moj profil → Praćenje i obavijesti**. Tablica izrađenih područja služi samo
pregledu: nema aktivne kontrole iznimaka po korisniku ni masovne izrade.
Isključivanje mogućnosti izrade ne briše postojeća područja ni njihova prava.

Tekst obavijesti sprema generički Notification modul. E-poruke se predaju opcionalnom E-mail modulu. Red sažetka je operativno stanje te se ne izlaže kao sadržaj znanja i ne arhivira u backupu.
