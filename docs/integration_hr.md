# Integracija i sigurnost

## Neutralni događaji

Auth objavljuje `UserAuthenticated` nakon uspješne lokalne, SAML, OIDC, OAuth2 ili CAS prijave i stvaranja sesije. Događaj sadrži samo ID korisnika, provider i opcionalni ključ profila. Simbioza User sluša neutralni događaj i poziva vlastiti servis osobnih područja, pa Auth nema ovisnost o Workspace ni ovom aplikacijskom modulu. Pogreška listenera zapisuje se u tehnički log, ali nikada ne poništava uspješnu prijavu.

Modul sluša događaje u vlasništvu drugih modula:

- Workspace objave te promjene stabla stranica i područja;
- nove Comment zapise i buduće događaje odgovora;
- Task završavanje i ponovno otvaranje te buduće događaje kreiranja ili promjene nositelja koje objavi Task;
- Calendar kreiranje, izmjenu, promjenu termina, brisanje događaja i promjenu pretplate.

Vlasnički moduli ostaju uporabivi bez Simbioza User modula. Događaje šalju kroz opcionalnu PSR-14 integraciju i nikada ne pozivaju ovaj modul izravno.

## Prikaz osobnog područja

Workspace izlaže generički `WorkspacePresentationRegistry`. Simbioza User pri
pokretanju modula registrira svoj provider i lokalizira generirani naziv i opis
osobnog područja prema trenutačnom jeziku sučelja. Prilagodba se radi grupno,
ne mijenja spremljeni Workspace zapis te čuva naziv ili opis koji vlasnik
naknadno prilagodi.

## Integracija korisničkog sučelja

Workspace prikazuje gumb praćenja kao posljednju akciju u postojećoj traci
akcija sadržaja. Razdjelnik odvaja tu osobnu akciju od akcija upravljanja
dokumentom, a uz ikonu zvona ostaje vidljiv tekst. Gumb zato ne stvara dodatni
red niti pomiče stablo, glavni sadržaj ili tablicu sadržaja prema dolje. Boje
normalnog, hover i aktivnog stanja koriste `document_action_*` tokene Theme
modula, a bez Theme modula ostaju čitljive kroz Bootstrap fallback vrijednosti.
Generički HTML Editor prihvaća opcionalni modularni partial kao vodeću akciju,
ali ne poznaje poslovna pravila praćenja.

## ACL pravila

1. `FollowService::follow()` provjerava cilj za trenutačnog korisnika prije spremanja.
2. `FollowDeliveryService` ponovno ga provjerava prije stvaranja obavijesti u aplikaciji ili e-poruke. Kod promjene ugrađenog kalendara ili liste zadataka dostavljene kroz praćenje stranice provjerava i izvornu komponentu i povezanu stranicu.
3. Slanje dnevnog sažetka još jednom provjerava svaki red.
4. `NotificationVisibilityRegistry` poziva Simbioza provider kada se obavijest broji, ispisuje, otvara, mijenja kroz web ili dohvaća kroz Notification API.
5. Pogreška ili nedostupnost ACL providera završava sigurnim uskraćivanjem pristupa.

Nazivi u `label_snapshot` služe samo za prikaz. Nakon gubitka ACL prava nikada se ne vraćaju korisniku.

## Backup

Backup cijelog sitea i poslovne cjeline **Korisnici** sprema globalne postavke,
sva praćenja, izričita isključenja automatskog praćenja, korisničke iznimke
izrade i mapiranja osobnih područja. Cjelina **Postavke** sprema globalno
pravilo izrade. Cjelina **Područja** i
backup pojedinog područja spremaju samo praćenja, osobne načine dostave i
kalendarske iznimke te mapiranje osobnog područja vezano uz obuhvaćena područja. Globalne osobne postavke ne
ulaze u scoped arhiv kako upravitelj područja ne bi mogao izvesti podatke koji
nisu dio njegova područja.

Restore ponovno mapira korisnike, područja, stranice i opcionalne kalendare
preko Backup prostora identiteta; UUID liste zadataka ostaje prenosivi
identifikator. Red dnevnih sažetaka se ne sprema, a normalni worker ponovno
stvara buduće operativno stanje. Zaostalo praćenje obrisanog korisnika preskače
se jer bez Auth identiteta ne pripada nijednom računu ciljnog sitea.

Copy import nikada ne zamjenjuje postojeće korisnikovo mapiranje osobnog područja. Uvezena kopija ostaje obično ograničeno područje istog vlasnika, čime se sprječava da se dva područja predstavljaju kao osobno područje istog korisnika.

## Proširenje domenskog modula

Objavite mali nepromjenjivi događaj samo sa stabilnim identifikatorima, ID-em izvršitelja, vrstom promjene i neosjetljivim prikaznim metapodacima. U Simbioza User dodajte listener koji događaj pretvara u `FollowActivity`. U događaje, obavijesti, audit metapodatke i tehničke logove ne stavljajte tijelo stranice ili komentara, tajne ni bajtove privitka.
