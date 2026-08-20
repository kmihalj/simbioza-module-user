# Personal follows API

The API module must be enabled. Every endpoint acts only on the user who owns the API key. Domain ACL is checked in addition to the API scope.

Scopes:

- `follows:read`
- `follows:write`
- `workspaces:read` for the personal-space status endpoint

## Personal space status

This read-only endpoint never creates a Workspace and returns only the API-key owner's mapping:

```bash
curl -H 'Authorization: Bearer hp_live_REPLACE_ME' \
  'https://example.test/api/v1/me/personal-workspace'
```

The response reports whether the space exists or was soft-deleted, whether automatic creation currently applies to the user, and the mapped Workspace ID, slug, name, and restricted visibility.

Allowed target types are `workspace`, `page`, `calendar`, and `task_list`.
`task_list` uses the stable list UUID returned by the Task API and requires
`document_id`; the list name may be supplied in `label`.

## List follows

The list reconciles current Calendar subscriptions before returning active
follows. Calendars whose notifications were explicitly disabled remain absent
until the owner follows them again in the profile or with `POST`.

```bash
curl -H 'Authorization: Bearer hp_live_REPLACE_ME' \
  'https://example.test/api/v1/me/follows?type=page&search=guide'
```

## Follow a page

```bash
curl -X POST \
  -H 'Authorization: Bearer hp_live_REPLACE_ME' \
  -H 'Content-Type: application/json' \
  -d '{"target_type":"page","target_id":"42"}' \
  'https://example.test/api/v1/me/follows'
```

Example successful envelope:

```json
{
  "data": {
    "target_type": "page",
    "target_id": "42",
    "accessible": true,
    "label": "Release guide",
    "url": "/w/team/release-guide"
  },
  "meta": {"request_id": "..."},
  "links": {"self": "/api/v1/me/follows"}
}
```

## Remove a follow

```bash
curl -X DELETE \
  -H 'Authorization: Bearer hp_live_REPLACE_ME' \
  'https://example.test/api/v1/me/follows/page/42'
```

Deleting the follow for a Calendar-subscribed calendar disables its Simbioza
notifications but does not cancel the Calendar subscription. A later `POST` for
the same calendar clears that opt-out and resumes notifications.

Example of following a complete task list:

```bash
curl -X POST \
  -H 'Authorization: Bearer hp_live_REPLACE_ME' \
  -H 'Content-Type: application/json' \
  -d '{"target_type":"task_list","target_id":"5adf2862-a532-4d66-b916-b977284fc159","document_id":"guide","label":"Release checklist"}' \
  'https://example.test/api/v1/me/follows'
```

## Read and update preferences

```bash
curl -H 'Authorization: Bearer hp_live_REPLACE_ME' \
  'https://example.test/api/v1/me/follow-preferences'

curl -X PATCH \
  -H 'Authorization: Bearer hp_live_REPLACE_ME' \
  -H 'Content-Type: application/json' \
  -d '{"email_enabled":true,"email_mode":"daily","notify_own_changes":false}' \
  'https://example.test/api/v1/me/follow-preferences'
```

Allowed `email_mode` values are `immediate`, `daily`, `important`, and `off`. Validation failures use the shared `application/problem+json` format.
