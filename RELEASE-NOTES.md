# Stonefellow v1.3.12 — Section 14: My Library, Collections & Saved Music

Section 13 Smart Radio was intentionally skipped. This release proceeds directly to the customer-facing Section 14 library experience.

- Added dedicated **My Library** navigation for signed-in customers.
- Added All, Tracks, Releases, Playlists, Purchases, Builds, History, and Collections tabs.
- Added unified library summary, in-library search, sorting, and responsive saved-item cards.
- Added Continue Listening directly to My Library with saved-position resume.
- Reused canonical favorites, playlists, saved builds, owned-order library, and listening history instead of duplicating those systems.
- Added mixed-content Collections containing tracks, releases, owned playlists, paid purchases, and owned saved builds.
- Added Collection create/edit/delete, add/remove, and manual item ordering.
- Added duplicate-safe Collection writes and removal activity history.
- Collection deletion preserves the underlying saved content.
- Added strict Collection/user ownership checks.
- Changed My Library purchase ownership so payment-pending orders do not appear as owned media.
- Consolidated saved-content management out of the oversized Account page into My Library.
- Kept billing, receipts, security, notification settings, and transactional order history in My Account.
- Added Agent routing for My Library and specific Library tabs.
- Added read-only Admin customer-library analytics and per-user inspection.
- Added dedicated `assets/js/library.js` bundle and release-gate syntax validation.
- Added migration 013.
- Application and database schema target advance to 1.3.12.
