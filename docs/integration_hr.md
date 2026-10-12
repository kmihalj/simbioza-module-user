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
naknadno prilagodi. Provider uz prikazni redak dodaje oznaku osobnog područja i
stabilni ID vlasnika. Generički Workspace pregled zato može odvojiti tuđa osobna
područja, a vlastito ostaviti u glavnom popisu, bez zaključivanja prema nazivu ili
slugu. Popisi i dalje nastaju tek nakon Workspace ACL provjere.

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

## Skupna provjera vidljivosti inboxa

`SimbiozaNotificationVisibilityProvider` implementira Notificationov opcionalni
paketni ugovor. `FollowTargetService::canViewTargets()` učita aktivnog korisnika
jednom po grupi, samo tražena područja/stranice i njihove aktivne pretke te
skupne ACL retke. Koristi uobičajena Workspace pravila (importna ograničenja
predaka, efektivne grupe, izravna prava krajnje stranice), bez stvaranja opisa
naslova/poveznice za svaki kandidat. Nepostojeći ciljevi, nepotpuni/ciklični
putovi, neaktivni korisnici i greške opcionalne integracije uskraćuju pristup.
ID-evi kalendara provjeravaju se kroz
`CalendarManagerInterface::readableCalendarIds()`; Calendar ostaje opcionalan,
a njegov modul zadržava vlastitu politiku čitanja. Obavijest ugrađenog kalendara
ili liste zadataka dodatno provjerava ciljnu stranicu.

Nijedna odluka ne preživljava operaciju, pa uklanjanje cilja ili ACL-a u istom
skupu servisa utječe na idući inbox/otvaranje/skupnu/API radnju. U aplikacijskom
SQLite testu sa svježim servisima 100 različitih stranica traži devet upita i
jedan dohvat korisnika, umjesto 504 upita i 100 dohvata. Veći inbox čita se u
grupama od 128 redaka; točan zbroj i dalje pregledava sve grupe kandidata.
To nije tvrdnja o konstantnom trošku za proizvoljno velik inbox.

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

## Trajno čišćenje područja

Prije nego Workspace ukloni svoje izvorne retke, Simbioza User briše sva
praćenja, posebne načine dostave, čekajuće dnevne sažetke, isključenja
automatskog praćenja i moguće mapiranje osobnog područja u tom opsegu. Retci
obavijesti ne prepisuju se jer su povijesni zapisi; aktualni provider vidljivosti
nakon nestanka cilja završava zatvoreno pa se naslov i poveznica više ne otkrivaju.

## Proširenje domenskog modula

Objavite mali nepromjenjivi događaj samo sa stabilnim identifikatorima, ID-em izvršitelja, vrstom promjene i neosjetljivim prikaznim metapodacima. U Simbioza User dodajte listener koji događaj pretvara u `FollowActivity`. U događaje, obavijesti, audit metapodatke i tehničke logove ne stavljajte tijelo stranice ili komentara, tajne ni bajtove privitka.
