# Stonefellow v1.3.10 — Section 11: Smart Notifications & Listener Re-engagement

- Added governed smart notifications for unfinished listening, personalized recommendations, new releases, and saved custom-media builds.
- Added per-category in-app/email preferences in My account.
- Email reminders reuse the existing transactional email system and are opt-in by default.
- Added persistent dedupe keys and first-run generation baseline to prevent duplicate notifications and upgrade storms.
- Added a CLI notification generator for inactive-user re-engagement.
- Extended the existing bell drawer with explicit Mark read, Open, and Dismiss actions.
- Added read/click/dismiss lifecycle instrumentation.
- Added Agent routes for notifications and notification preferences.
- Added notification unread/latest-title context to Agent routing.
- Added Admin delivery/read/click metrics, notification→listen conversion, notification→purchase conversion, type breakdowns, user breakdowns, and recent notification activity.
- Added notification KPIs to the main Admin dashboard.
- Cleaned the Section 10 analytics renderer markup while preserving its listening and commerce analytics.
- Updated Sections 7–10 regression gates so later schema migrations do not falsely invalidate completed earlier sections.
- Added migration 011.
- Application and database schema target advance to 1.3.10.
