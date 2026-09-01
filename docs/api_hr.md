# API osobnih praćenja

API modul mora biti uključen. Svaka ruta radi isključivo nad korisnikom koji je vlasnik API ključa. Uz API scope uvijek se provjerava i domenski ACL.

Scopeovi:

- `follows:read`
- `follows:write`
- `workspaces:read` za stanje osobnog područja

## Stanje osobnog područja

Ova read-only ruta nikada ne izrađuje područje i vraća samo mapiranje vlasnika API ključa:

```bash
curl -H 'Authorization: Bearer hp_live_ZAMIJENI_ME' \
  'https://primjer.test/api/v1/me/personal-workspace'
```

Odgovor navodi postoji li područje ili je soft-obrisano, vrijedi li za korisnika automatska izrada te ID, slug, naziv i ograničenu vidljivost mapiranog Workspacea.

Dopuštene vrste cilja su `workspace`, `page`, `calendar` i `task_list`.
`task_list` koristi stabilni UUID liste koji vraća Task API te uz zahtjev treba
poslati `document_id`; naziv liste može se poslati u polju `label`.

## Popis praćenja

Prije povrata aktivnih praćenja popis usklađuje aktualne Calendar pretplate.
Kalendari kojima su obavijesti izričito isključene ne vraćaju se sve dok ih
vlasnik ponovno ne uključi u profilu ili putem `POST` zahtjeva.

```bash
curl -H 'Authorization: Bearer hp_live_ZAMIJENI_ME' \
  'https://primjer.test/api/v1/me/follows?type=page&search=upute'
```

## Praćenje stranice

```bash
curl -X POST \
  -H 'Authorization: Bearer hp_live_ZAMIJENI_ME' \
  -H 'Content-Type: application/json' \
  -d '{"target_type":"page","target_id":"42"}' \
  'https://primjer.test/api/v1/me/follows'
```

Primjer uspješnog odgovora:

```json
{
  "data": {
    "target_type": "page",
    "target_id": "42",
    "accessible": true,
    "label": "Upute za izdanje",
    "url": "/w/tim/upute-za-izdanje"
  },
  "meta": {"request_id": "..."},
  "links": {"self": "/api/v1/me/follows"}
}
```

## Prestanak praćenja

```bash
curl -X DELETE \
  -H 'Authorization: Bearer hp_live_ZAMIJENI_ME' \
  'https://primjer.test/api/v1/me/follows/page/42'
```

Brisanje praćenja kalendara na koji je korisnik pretplaćen isključuje Simbioza
obavijesti, ali ne prekida Calendar pretplatu. Naknadni `POST` za isti kalendar
uklanja to isključenje i ponovno uključuje obavijesti.

Primjer praćenja cijele liste zadataka:

```bash
curl -X POST \
  -H 'Authorization: Bearer hp_live_ZAMIJENI_ME' \
  -H 'Content-Type: application/json' \
  -d '{"target_type":"task_list","target_id":"5adf2862-a532-4d66-b916-b977284fc159","document_id":"upute","label":"Kontrolna lista objave"}' \
  'https://primjer.test/api/v1/me/follows'
```

## Čitanje i izmjena postavki

```bash
curl -H 'Authorization: Bearer hp_live_ZAMIJENI_ME' \
  'https://primjer.test/api/v1/me/follow-preferences'

curl -X PATCH \
  -H 'Authorization: Bearer hp_live_ZAMIJENI_ME' \
  -H 'Content-Type: application/json' \
  -d '{"email_enabled":true,"email_mode":"daily","notify_own_changes":false,"theme_mode":"dark"}' \
  'https://primjer.test/api/v1/me/follow-preferences'
```

Dopuštene vrijednosti `email_mode` su `immediate`, `daily`, `important` i
`off`, a vrijednosti `theme_mode` su `auto`, `light`, `dark` i `system`.
`theme_mode` se može promijeniti samo dok je globalni Theme način `auto`;
prisilno svijetla ili tamna politika odbija to polje i zanemaruje ranije
spremljenu osobnu vrijednost. Pogreške validacije koriste zajednički format
`application/problem+json`.
