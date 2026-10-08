# Stonefellow v1.3.7 — Section 8: Custom Record / Mixtape v2

Stonefellow v1.3 Section 8 upgrades the existing custom-media builder instead of introducing a second build model.

## Included
- Precise drag-and-drop ordering within and across Side A / Side B.
- Live used and remaining time per side, including exact over-limit correction.
- Duplicate-track detection and one-click cleanup.
- Personalized **Fill remaining** for either side or the whole build.
- Authenticated fill uses the existing listening/favorites/playlist recommendation profile.
- Guest fill retains a local similarity-based fallback.
- Account-backed saved drafts with load, update, and delete.
- Local v2/v3 build state migrates into builder v4.
- Richer vinyl sleeve artwork collage and cassette preview.
- Exact Side A / Side B sequence shown in the cart.
- Server-validated manufacturing sequence shown again at checkout.
- Agent commands for move-to-side, fill/finish, remove duplicates, and save draft.
- Existing cart, quote, order, library, and POD handoff paths remain authoritative.

## Header menu refinement
The signed-in dropdown uses tighter rows and identity/version spacing so all account links remain visible in normal desktop viewports. Short-height displays receive an additional compact rule, and the menu is viewport constrained with overflow as a fallback.

## Database
No new migration is required for Section 8. Existing `user_saved_builds` already supports account drafts.

Application: **1.3.7**  
Database schema target: **1.3.6**
