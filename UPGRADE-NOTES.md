# Upgrade to Stonefellow v1.3.10 Section 11

Upload/extract the update over the existing Stonefellow installation, then run:

```
/upgrade.php
```

Section 11 advances the database schema from **1.3.6** to **1.3.10** with:

- `2026-10-08-011` — Smart notification preferences, persistent dedupe, generation baselines, lifecycle events, and notification conversion analytics.

New tables:

- `user_notification_preferences`
- `notification_delivery_keys`
- `notification_events`
- `notification_generation_state`

Existing `user_notifications` remains the authoritative in-app notification inbox.

## Important first-run behavior

The first Section 11 notification-generation pass establishes a baseline and seeds existing releases. It intentionally does **not** send historical re-engagement notifications. Old unfinished listening, old saved builds, and releases that already existed at upgrade time will not cause a notification storm.

## Scheduled re-engagement

For reminders to reach users who are not actively browsing Stonefellow, schedule:

```sh
php /path/to/stonefellow/cron-notifications.php
```

Hourly is a reasonable default. Persistent dedupe makes repeated executions safe.

## Email

Smart notification email is disabled by default until each user opts in. Delivery uses the existing transactional email configuration; no new mail credentials are introduced by Section 11.

Existing users, purchases, favorites, playlists, listening history, recommendations, Agent sessions, queues, releases, saved builds, and analytics are preserved.
