# Korištenje praćenja

## Početak i prestanak praćenja

Prijavljeni čitatelj može pratiti sadržaj samo dok vlasnički modul potvrđuje pravo čitanja. Dostupne kontrole su:

- kontrola područja na naslovnici područja;
- kontrola stranice na objavljenoj stranici;
- jedna kontrola uz cijelu ugrađenu listu zadataka;
- postojeća Calendar pretplata koja se sinkronizira sa Simbioza User modulom.

Pretplata na kalendar u Calendar modulu ujedno je kontrola praćenja: nije
potreban drugi gumb. Nove pretplate sinkroniziraju se odmah, a pretplate koje su
postojale prije instalacije ovog modula automatski se usklađuju pri otvaranju
profila i prije dostave sljedeće promjene tog kalendara.

Pretplata i praćenje obavijesti ipak su odvojena stanja. U tablici profila
**Prestani pratiti** utišava promjene tog kalendara, ali ne prekida Calendar
pretplatu. Pretplaćeni kalendar ostaje vidljiv s isključenim obavijestima i
akcijom **Prati**, pa korisnik obavijesti može ponovno uključiti u bilo kojem
trenutku. Brisanje Calendar pretplate uklanja automatsko praćenje i oznaku
isključenja.

Osobni profil prikazuje vrstu cilja, aktualni ACL-sigurni naziv, datum početka
praćenja, opcionalnu iznimku e-pošte i poveznicu. Polje za pretragu filtrira
prema nazivu. Filtar stanja nudi sve stavke, pratim/ne pratim, samo u
aplikaciji, e-pošta odmah, dnevni sažetak i samo važne promjene. Akcija sa
zvonom mijenja stanje praćenja, a skup gumba dostave jasno prikazuje jedan
aktivni način za svaku stavku: neaktivni gumbi su obrubljeni i izdignuti, a
aktivni je pun i utisnut. Opis ispod gumba objašnjava trenutačni izbor. Promjena
načina i spremanje osobnih postavki
odvijaju se u pozadini: profil se ne osvježava, otvoreni accordioni i položaj
stranice ostaju sačuvani, a rezultat potvrđuje tematska toast poruka. Cilj kojem
je pristup izgubljen nikada se ne prikazuje sa starim nazivom.

Lista zadataka prati se kao jedna poslovna cjelina. Njezini retci ostaju
pojedinačni checkboxovi, ali lista ima samo jedan gumb praćenja i jedan podatak
o zadnjoj promjeni. Promjena bilo kojeg retka obavještava pratitelje te liste,
a poruka navodi koji se zadatak promijenio.

Kada praćena stranica sadrži ugrađeni kalendar ili listu zadataka, dopuštena
promjena ugrađene komponente računa se i kao promjena stranice. Korisnik ne mora
zasebno pratiti taj kalendar ili listu. Ako prati preklapajuće ciljeve, jedna
poslovna promjena i dalje stvara samo jednu obavijest. Prije dostave ponovno se
provjerava ACL promijenjenog izvora i povezane stranice.

## Načini dostave

- **Odmah**: stvara obavijest u aplikaciji i odmah stavlja e-poruku u red.
- **Dnevni sažetak**: odmah stvara obavijest u aplikaciji, a e-poruke objedinjuje u jedan sažetak koji dospijeva sljedećeg dana u 08:00 prema vremenskoj zoni aplikacije. Worker ga sigurno može preuzeti pri prvom sljedećem pokretanju.
- **Samo važne promjene**: sve dopuštene promjene i dalje su odmah u aplikaciji, dok se e-pošta šalje samo za objavu ili uklanjanje stranice, uklanjanje događaja ili promjenu njegova termina te dovršavanje, ponovno otvaranje ili promjenu nositelja zadatka.
- **Samo u aplikaciji**: nikada ne stavlja kopiju e-pošte u red.
- **Obavijesti o vlastitim promjenama**: zadano je isključeno radi smanjenja nepotrebnih poruka.

Za stranicu se obavijest stvara nakon objave nove verzije. Spremanje nacrta ne
obavještava pratitelje. Obavijesti u aplikaciji odmah su dostupne u korisničkom
izborniku pod **Obavijesti**; odabrani ritam utječe na e-poštu, ne na inbox.

Glavni prekidač „šalji e-mail obavijesti” iz Notification modula uvijek ima prednost. Ako je isključen, Simbioza User ne šalje e-poštu bez obzira na odabrani ritam.

Obavijest kalendara navodi je li događaj dodan, ažuriran, uklonjen ili mu je
promijenjen termin, njegov naslov i datum/vrijeme. Kod promjene termina prikazuje
i prethodni i novi termin. Isti opis ulazi u inbox aplikacije, neposrednu e-poštu
i dnevni sažetak.

## Worker dnevnog sažetka

Naredbu pokrećite redovito. Sigurno ju je pokretati svakih 5–15 minuta jer se dostavljeni redovi označavaju atomarno:

```bash
php vendor/bin/hph simbioza-user:dispatch --limit=500
```

Primjer crona:

```cron
*/10 * * * * cd /srv/simbioza && php vendor/bin/hph simbioza-user:dispatch --limit=500
```

## Uklanjanje duplikata

Ako korisnik prati i područje i jednu njegovu stranicu, jedan domenski događaj stvara jednu obavijest. Istovrsni brzi događaji dijele kratki vremenski ključ. Red dnevnog sažetka povećava broj ponavljanja umjesto stvaranja mnogo gotovo jednakih e-poruka.
