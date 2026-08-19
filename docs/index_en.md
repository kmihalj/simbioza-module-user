# Simbioza User guide

Simbioza User is an application-level coordinator. It does not duplicate Workspace, Calendar, Comment, Task, Notification, or E-mail business logic. Owning modules publish neutral events; this module resolves personal follows, applies current ACL, and selects a delivery channel.

## Reading path

1. [Using follows](usage_en.md) for administrators and end users.
2. [API](api_en.md) for API-key owners and integrators.
3. [Integration and security](integration_en.md) for developers and operators.

The Croatian documentation is available in [index_hr.md](index_hr.md).

## Data ownership

The module owns four portable tables:

- `simbioza_user_preferences`: one default delivery policy per user;
- `simbioza_user_follows`: durable polymorphic follows;
- `simbioza_user_follow_exclusions`: explicit opt-outs from automatic follows, currently used for subscribed calendars;
- `simbioza_user_pending_deliveries`: transient daily-digest queue.

Notification text is stored by the generic Notification module. E-mail messages are passed to the optional E-mail module. The digest queue is operational state and is neither exposed as knowledge content nor archived in backup.
