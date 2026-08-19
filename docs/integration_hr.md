# Integracija i sigurnost

## Neutralni događaji

Modul sluša događaje u vlasništvu drugih modula:

- Workspace objave te promjene stabla stranica i područja;
- nove Comment zapise i buduće događaje odgovora;
- Task završavanje i ponovno otvaranje te buduće događaje kreiranja ili promjene nositelja koje objavi Task;
- Calendar kreiranje, izmjenu, promjenu termina, brisanje događaja i promjenu pretplate.

Vlasnički moduli ostaju uporabivi bez Simbioza User modula. Događaje šalju kroz opcionalnu PSR-14 integraciju i nikada ne pozivaju ovaj modul izravno.

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

Postavke, praćenja i izričita isključenja automatskog praćenja pripadaju poslovnoj cjelini **Korisnici**. ID-evi korisnika, područja, stranice i opcionalnog kalendara pri restoreu koriste Backup prostore identiteta. UUID zadatka je prenosivi identifikator. Red dnevnih sažetaka se ne sprema, a normalni worker ponovno stvara buduće operativno stanje.

## Proširenje domenskog modula

Objavite mali nepromjenjivi događaj samo sa stabilnim identifikatorima, ID-em izvršitelja, vrstom promjene i neosjetljivim prikaznim metapodacima. U Simbioza User dodajte listener koji događaj pretvara u `FollowActivity`. U događaje, obavijesti, audit metapodatke i tehničke logove ne stavljajte tijelo stranice ili komentara, tajne ni bajtove privitka.
