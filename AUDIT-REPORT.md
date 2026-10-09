# Stonefellow v1.3.10 — Section 11 Smart Notifications & Listener Re-engagement Audit

## 1. Notification governance — 10/10
- Smart notification categories are explicitly defined.
- Optional re-engagement notifications default to in-app enabled and email disabled.
- Account/security/billing/order service notifications remain outside optional re-engagement preferences.
- Persistent per-user/category preferences survive sessions and devices.
- Notification writes remain authenticated and CSRF protected.

## 2. Duplicate and upgrade-storm prevention — 10/10
- Persistent dedupe keys are unique per user and notification opportunity.
- Duplicate-key handling distinguishes expected conflicts from unrelated database failures.
- First use establishes a generation baseline before creating re-engagement notifications.
- Existing releases are seeded into the baseline.
- Old listening progress and old saved builds cannot suddenly generate notifications after upgrade.
- Read/click/dismiss delivery lifecycle events are de-duplicated per notification.

## 3. Re-engagement intelligence — 10/10
- Continue Listening reminders use canonical listening-progress state and wait at least 12 hours.
- Saved-build reminders use canonical saved builds and wait at least 24 hours.
- New-release notifications use canonical published/visible releases.
- Recommendation notifications use the existing personalization engine and its recommendation reasons.
- No parallel favorites, history, recommendation, release, or build state was introduced.

## 4. In-app experience — 10/10
- Existing bell drawer remains the single notification surface.
- Notifications support Mark read, Open, Dismiss, Mark all read, and Refresh.
- Dismissed notifications no longer clutter the visible drawer.
- Notification badge/unread state stays synchronized with actions.
- Existing History and Agent Brain tabs remain intact.

## 5. Email and inactive-user delivery — 10/10
- Email is opt-in per smart-notification category.
- Delivery reuses Stonefellow's transactional email outbox.
- Existing log/php_mail delivery configuration remains authoritative.
- Email delivery status is recorded for analytics.
- `cron-notifications.php` is CLI-only and can safely process active accounts on a schedule.

## 6. Agent integration — 10/10
- The Agent can open the notification drawer.
- The Agent can direct users to notification preferences.
- Agent routing receives unread count and latest notification context.
- Notification actions do not bypass existing user controls or preferences.

## 7. Notification analytics — 10/10
- Delivery, read, click, dismiss, and email-delivery events are recorded.
- Read and click rates are calculated.
- Click→listen and click→paid-purchase conversions use a 24-hour attribution window.
- Paid-order attribution reuses the canonical Section 10 paid-order classifier.
- Analytics include notification type and per-user engagement.
- Email-only events retain category attribution.

## 8. Admin integration — 10/10
- Listening & Conversion Analytics now includes Notification re-engagement.
- Main Admin dashboard shows delivery, click rate, click→listen, and click→purchase.
- Main dashboard includes recent notification activity.
- Per-user analytics include notification outcomes alongside listening, favorites, playlists, builds, purchases, and Agent Brain.

## 9. Migration and compatibility — 10/10
- Migration 011 creates the preference, dedupe, event, and generation-state tables.
- Database schema target advances to 1.3.10.
- Earlier Section 7–10 gates now verify their own capabilities/migrations instead of freezing the global schema target.
- Sections 1–11 remain in the release gate.

The measured release result is recorded in `TEST-RESULTS.txt`.
