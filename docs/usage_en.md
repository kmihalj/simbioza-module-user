# Using follows

## Follow and unfollow

An authenticated reader can follow content only while its owning module confirms read access. Available controls are:

- a workspace-level control on the workspace home page;
- a page-level control on a published page;
- one control for a complete embedded task list;
- the existing Calendar subscription control, synchronized with Simbioza User.

A Calendar-module subscription is also the follow control; no second button is
required. New subscriptions are synchronized immediately, while subscriptions
that predate this module are reconciled automatically when the profile opens
and before the next change to that calendar is delivered.

Subscription and notification following are nevertheless separate states. In
the profile table, **Stop following** silences changes from that calendar but
keeps the Calendar subscription. The subscribed calendar remains visible with
notifications off and a **Follow** action, so the user can resume notifications
at any time. Removing the Calendar subscription removes both the automatic
follow and its opt-out marker.

The personal profile lists the target type, current ACL-safe label, follow date,
optional e-mail override, and a link. Its search field filters by name. The
state filter offers all items, followed/not followed, in-app only, immediate
e-mail, daily digest, and important changes only. The bell action changes
follow state; the delivery-button group visibly exposes exactly one active mode
per item: inactive buttons are outlined and raised, while the active one is
filled and pressed. Text below the buttons explains the current choice.
Changing a delivery mode and saving personal preferences happens in the
background: the profile is not reloaded, open accordions and scroll position
remain intact, and a themed toast confirms the result. A target that is no
longer accessible is never displayed with its old label.

A task list is followed as one business unit. Its rows remain individual
checkboxes, but the list has only one follow button and one latest-change
summary. Changing any row notifies that list's followers, while the message
identifies the task that changed.

When a followed page embeds a calendar or task list, a permitted change to the
embedded component also counts as a page change. A user does not need to follow
that calendar or task list separately. If the user follows overlapping targets,
one domain change still creates only one notification. Both the changed source
and the related page are checked against current ACL before delivery.

## Delivery choices

- **Immediately**: create the in-app notification and queue an e-mail at once.
- **Daily digest**: create the in-app notification immediately and combine e-mail copies into one digest due at 08:00 on the following day in the application time zone. The next worker run safely picks it up.
- **Important changes only**: keep every permitted change immediately visible in the application, while sending e-mail only for page publication or removal, event removal or schedule changes, and task completion, reopening, or assignee changes.
- **In-app only**: never queue an e-mail copy.
- **Notify about my own changes**: off by default to avoid noise.

A page notification is created after a new version is published. Saving a draft
does not notify followers. In-app notifications are available immediately under
**Notifications** in the user menu; the chosen cadence affects e-mail, not the
inbox.

The master “send e-mail notifications” switch from Notification remains authoritative. If it is off, Simbioza User does not send e-mail regardless of cadence.

A calendar notification states whether an event was added, updated, removed,
or rescheduled and includes its title and date/time. Rescheduling includes both
the previous and new schedule. The same description is used by the in-app
inbox, immediate e-mail, and daily digest.

## Daily worker

Run the command regularly; running it every 5–15 minutes is safe because delivered rows are marked atomically:

```bash
php vendor/bin/hph simbioza-user:dispatch --limit=500
```

Example cron entry:

```cron
*/10 * * * * cd /srv/simbioza && php vendor/bin/hph simbioza-user:dispatch --limit=500
```

## De-duplication

If a user follows both a workspace and one of its pages, one domain event produces one notification. Equivalent rapid events share a short time-window key. Daily rows keep an occurrence count instead of creating many nearly identical e-mails.
