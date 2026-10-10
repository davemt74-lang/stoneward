# Stonefellow v1.3.21 — Section 22: Fan Segments, Automations & Lifecycle Journeys

Stonefellow remains a **single-artist direct-to-fan platform**. Section 22 turns the Section 17 CRM, Section 18 Campaigns and Section 21 commerce intelligence into an always-on fan relationship layer.

Campaigns remain finite promotions. **Lifecycle Automations** are persistent journeys that react to fan behavior over time.

## Dynamic fan segments

Admin now includes reusable **Fan Segments** evaluated from current data rather than copied lists.

Segment conditions can use:

- CRM stage
- newsletter consent
- proactive-Agent permission
- linked-account state
- CRM tags
- contact source
- contact age
- days since engagement
- CRM event history
- purchased merch product
- merch spend
- merch units
- listening-event count
- days since listening

Segments support **Match ALL** and **Match ANY** logic and include a live preview/count before they are used.

## Lifecycle triggers

Automations support:

- Segment entry / match
- Daily eligibility
- Newsletter signup
- Merch purchase
- Campaign conversion
- Inactivity threshold
- Manual run

CRM-event triggers use the immutable CRM event ID as the trigger key. Segment-entry and inactivity journeys are version-aware. Daily/manual triggers remain controlled by cooldown and max-enrollment limits.

## Journey actions

Lifecycle actions run sequentially:

- Wait
- Add CRM tag
- Change CRM stage
- In-app notification
- Agent message
- Marketing email
- Exit journey

**Wait** persists the fan's exact step and a future due time. A later cron run resumes the same enrollment.

## Delivery governance

Each channel has its own boundary:

- **In-app lifecycle notification:** requires linked account and respects the fan's Lifecycle Messages notification preference.
- **Agent message:** requires linked account **and** proactive-Agent permission.
- **Marketing email:** requires newsletter opt-in and includes a fresh unsubscribe link.
- **CRM tag/stage:** operates only inside the CRM profile.
- **Activation:** requires explicit Admin confirmation.
- **Run now:** requires explicit Admin confirmation.

Saving an active automation automatically pauses it and increments its version. The Admin must review and reactivate the new version.

## Dedupe, retries and reconnect safety

Lifecycle uses two durable safeguards:

1. Enrollment uniqueness: automation + fan + trigger key.
2. Action uniqueness: a persistent dedupe key for every enrollment step.

A cron retry or reconnect therefore cannot resend a successful action. Failed actions have a bounded retry limit rather than retrying forever.

Automations also support:

- cooldown hours
- max enrollments per fan
- active / paused / archived state
- per-action success/skip/error ledger
- complete run history

## Admin workspace

**Segments + Automations** is a first-class Admin section with:

- dynamic segment list
- segment editor and live preview
- lifecycle journey list
- sequential Journey Builder
- trigger and segment configuration
- cooldown / enrollment caps
- channel-governance notices
- Activate / Pause / Archive
- Run now
- enrollment history
- action ledger
- cron/manual run history

Admin Agent can route requests about segments, re-engagement, win-back and lifecycle journeys into this workspace.

Lifecycle saves, activation/status changes and manual runs feed Admin Agent Brain.

## Scheduled execution

`cron-lifecycle.php` is a CLI-only runner:

`php cron-lifecycle.php`

It evaluates all active lifecycle automations, enrolls newly eligible fans and executes actions that are due.

It is intentionally separate from `cron-notifications.php`; smart listening/release reminders and CRM lifecycle journeys have different governance and dedupe models.

## Database

Migration **2026-10-10-019** adds:

- `fan_segments`
- `lifecycle_automations`
- `lifecycle_enrollments`
- `lifecycle_action_log`
- `lifecycle_runs`

Application: **1.3.21**  
Database schema target: **1.3.18**
