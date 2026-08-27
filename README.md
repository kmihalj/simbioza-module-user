# Simbioza User module

Personal following, in-application notifications, and optional e-mail delivery for the Simbioza knowledge application.

Croatian documentation: [README_hr.md](README_hr.md)

## Dependencies

Required Composer packages, enabled before this module:

- `aaieduhr/heartphrame-framework`
- `aaieduhr/heartphrame-module-auth`
- `aaieduhr/heartphrame-module-orm`
- `aaieduhr/heartphrame-module-notification`
- `aaieduhr/heartphrame-module-workspace`

Optional integrations are detected at runtime: `heartphrame-module-api`, `heartphrame-module-audit`, `heartphrame-module-backup`, `heartphrame-module-calendar`, `heartphrame-module-comment`, `heartphrame-module-email`, `heartphrame-module-task`, and `heartphrame-module-theme`.

## What it provides

- follow an individual Workspace page, a complete workspace, a calendar, or a complete embedded task list;
- provision one ordinary restricted personal Workspace per user after the first successful sign-in, with the user's complete ACL, an administrator-controlled global rule, and per-user exceptions;
- automatically reconcile both new and pre-existing Calendar subscriptions, while allowing notifications to be disabled and re-enabled without cancelling the subscription;
- use a separated icon-and-label follow action styled by light/dark Theme document-action tokens;
- manage follows in an ACL-safe profile table with search, state filters, a per-item follow toggle, and compact delivery icon buttons saved without a page reload;
- choose immediate e-mail, a daily digest, important changes only, or in-app notifications only;
- optionally suppress notifications caused by the user's own changes;
- de-duplicate overlapping workspace/page follows and bursts of equivalent changes;
- treat a change to a calendar or task list embedded in a followed page as a change to that page;
- include the action, event, and schedule in calendar notifications, including old and new schedules after rescheduling;
- re-check ACL both before delivery and whenever an existing notification is displayed or opened;
- expose the owner's follows, preferences, and personal-space status through the optional modular API;
- include durable follows, preferences, personal-space mappings, and provisioning settings in the appropriate Users, Workspaces, and Settings backups;
- record follow and preference changes through the optional Audit module.

Site and Users backups restore global personal preferences, while a Workspace
backup restores only follows and delivery overrides related to that Workspace.
Transient daily-digest queue rows are deliberately excluded from backup.

Permanent deletion of an already deleted Workspace removes related follows,
delivery overrides, pending deliveries, opt-out records, and a possible
personal-Workspace mapping. Existing in-app notifications are protected by the
same live ACL visibility check and are no longer shown after the target vanishes.

## Quick start

```bash
composer require aaieduhr/simbioza-module-user:dev-main
php vendor/bin/hph simbioza-user:install-migration
php vendor/bin/hph orm-migrate:up
```

An existing installation upgrades with:

```bash
php vendor/bin/hph simbioza-user:install-personal-workspaces-migration
php vendor/bin/hph orm-migrate:up
```

Administrators configure personal Workspaces under **Settings → Workspaces → Personal Workspaces**. The default enables creation after a user's first successful local, SAML, OIDC, OAuth2, or CAS sign-in and grants the mapped user View, Add, Edit, Publish, Delete, and Manage. A batch action provisions eligible existing active users. Each user can be excluded without changing Workspace or Auth behavior.

Enable `aaieduhr/simbioza-module-user` after its required modules. The Auth profile joins basic account data to its security accordion and keeps **Personal settings** permanently open in a separate card. **Notification settings** also stay open, while the table is searched and filtered inside the **Followed content** accordion. Page/workspace/task-list controls appear automatically where their owning modules render them; Calendar subscriptions are synchronized with the unified list.

Run due daily digests from a worker or cron job:

```bash
php vendor/bin/hph simbioza-user:dispatch --limit=500
```

## Documentation

- [English guide](docs/index_en.md)
- [Using follows](docs/usage_en.md)
- [API](docs/api_en.md)
- [Integration and security](docs/integration_en.md)

## Development checks

```bash
vendor/bin/phpcs -p
vendor/bin/phpstan --memory-limit=1024M
vendor/bin/phpstan analyze -c phpstan-dev.neon --memory-limit=1024M
vendor/bin/phpunit
```

License: EUPL-1.2.
