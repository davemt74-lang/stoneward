# Stonefellow v1.3.10 — Section 11: Smart Notifications & Listener Re-engagement

Stonefellow v1.3 Section 11 turns the existing bell/activity drawer into a governed re-engagement system driven by the canonical listening, recommendation, release, and saved-build data already built in Sections 1–10.

## Included

- Personalized in-app reminders for unfinished listening.
- Personalized recommendation notifications using the existing recommendation engine.
- New-release notifications using canonical published releases.
- Saved custom-record/cassette draft reminders.
- Per-category notification preferences:
  - In app
  - Email
- Email delivery reuses Stonefellow's existing transactional email outbox/delivery configuration.
- Existing account, security, billing, and order service notifications remain separate from optional re-engagement preferences.
- Mark read, Open, Dismiss, Mark all read, and Refresh controls in the existing bell drawer.
- Persistent notification dedupe keys so the same event cannot repeatedly notify a user.
- A first-run baseline that prevents historical releases, old listening positions, or old builds from creating an upgrade notification storm.
- CLI generation for inactive users: `php cron-notifications.php`.
- Agent commands for opening notifications and locating notification preferences.
- Agent context includes unread count and the latest notification title.
- Admin notification analytics for delivery, reads, clicks, dismissals, email delivery, click→listen conversion, and click→purchase conversion.
- Per-type and per-user notification engagement.
- Main Admin dashboard notification KPIs plus recent notification activity.

## Notification timing

- Continue Listening reminder: eligible after 12 hours.
- Saved build reminder: eligible after 24 hours.
- Recommendation: at most once per dedupe week/track.
- New releases: deduped permanently per user/release.
- Every smart notification is protected by a persistent per-user dedupe key.

The CLI generator is idempotent and safe to schedule repeatedly. **Hourly** is a reasonable default:

```sh
php /path/to/stonefellow/cron-notifications.php
```

The signed-in bell drawer also performs a current-user generation check when refreshed, so active users do not depend exclusively on cron.

## Preferences and email

Smart re-engagement categories default to:

- In-app: enabled
- Email: disabled

Users can change these under **My account → Notifications**.

Email uses the existing Stonefellow transactional email configuration. If delivery mode is `log`, messages are written to the transactional outbox without external delivery. If `php_mail` is enabled, Stonefellow attempts delivery through PHP `mail()`.

## Database

Section 11 advances the database schema to **1.3.10**.

Migration:

- `2026-10-08-011` — notification preferences, persistent dedupe keys, generation baselines, lifecycle events, and conversion analytics.

Run **`/upgrade.php`** after deploying v1.3.10.

Application: **1.3.10**  
Database schema target: **1.3.10**
