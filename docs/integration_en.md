# Integration and security

## Neutral events

Auth publishes `UserAuthenticated` after successful local, SAML, OIDC, OAuth2, or CAS session creation. It contains only the user ID, provider, and optional profile key. Simbioza User listens to that neutral event and calls its own personal-space service, so Auth has no dependency on Workspace or this application module. Listener failures are logged but never invalidate an authenticated session.

The module listens to events owned by other modules:

- Workspace publication, page-tree, and workspace changes;
- new Comment entries and future reply events;
- Task completion/reopen events and any future creation/assignee events emitted by Task;
- Calendar event creation, update, schedule change, deletion, and subscription change.

Owning modules remain usable without Simbioza User. They dispatch events through optional PSR-14 integration and never call this module directly.

## Personal-space presentation

Workspace exposes a generic `WorkspacePresentationRegistry`. Simbioza User
registers its provider during module bootstrap and localizes generated personal-
space names and descriptions for the current interface language. Presentation
is batched, does not mutate stored Workspace data, and preserves a title or
description that the owner customized later.

## User-interface integration

Workspace renders the follow button as the final action in the existing content
toolbar. A separator distinguishes this personal action from document-management
actions, while the visible label remains next to the bell icon. The button
therefore does not create another row or push the page tree, main content, or
table of contents down. Its normal, hover, and active colors use the Theme
module's `document_action_*` tokens and remain readable without Theme through
Bootstrap fallbacks. The generic HTML Editor accepts an optional module partial
as a leading action but does not know follow-domain rules.

## ACL invariants

1. `FollowService::follow()` resolves the target for the current user before writing.
2. `FollowDeliveryService` resolves it again before creating an in-app or e-mail delivery. For an embedded calendar/task-list change delivered through a page follow, it validates both the source component and the related page.
3. Daily digest dispatch resolves every queued row once more.
4. `NotificationVisibilityRegistry` invokes the Simbioza provider when an inbox row is counted, listed, opened, mutated through the web UI, or accessed through the Notification API.
5. A failed or unavailable ACL provider fails closed.

Labels stored in `label_snapshot` are presentation aids only. They are not returned after ACL is lost.

## Backup

A full-site backup and the **Users** business component store global preferences,
all follows, explicit automatic-follow exclusions, per-user creation policies,
and personal-space mappings. The **Settings** component stores the global
creation rule. The **Workspaces**
component and a single-Workspace backup store only follows, personal delivery
overrides, calendar exclusions, and a personal-space mapping related to the included Workspaces. Global
personal preferences stay outside a scoped archive so a Workspace manager
cannot export data unrelated to that Workspace.

Restore remaps users, Workspaces, pages, and optional calendars through Backup
identity namespaces; a task-list UUID remains portable. Pending digests are
excluded and the normal worker recreates future operational state. A stale
follow belonging to a deleted user is skipped because it belongs to no Auth
account on the target site.

A copy import never replaces a user's existing personal-space mapping. The imported copy remains an ordinary restricted Workspace owned by that user, preventing two spaces from claiming to be the same user's personal home.

## Extending a domain module

Publish a small immutable event containing only stable identifiers, actor ID, change type, and non-sensitive display metadata. Add a listener in Simbioza User that converts the event into `FollowActivity`. Do not include page bodies, comment bodies, secrets, or attachment bytes in events, notifications, audit metadata, or technical logs.
