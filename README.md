# Stonefellow v1.3.12 — Section 14: My Library, Collections & Saved Music

Section 14 gives every signed-in listener a dedicated **My Library** workspace. It consolidates the saved-content systems already built across Stonefellow without replacing or duplicating them.

## My Library

The signed-in menu now includes **My Library** with first-class tabs for:

- All
- Tracks
- Releases
- Playlists
- Purchases
- Builds
- History
- Collections

The library reuses Stonefellow's canonical favorites, playlists, owned-order library, saved custom-media builds, Continue Listening state, and listening history.

### Library actions

From My Library a listener can:

- Play saved tracks.
- Resume unfinished listening.
- Open saved tracks and releases.
- Play or open playlists.
- Add tracks to Up Next.
- Reopen saved record/cassette builds.
- Open the source order for owned purchases.
- Favorite/unfavorite tracks and releases using the existing personalization system.
- Search and sort within the current library tab.
- Organize saved content into mixed Collections.

## Collections

Collections are deliberately different from playlists. A playlist is an ordered music playback sequence; a Collection is a personal folder that can contain different kinds of saved Stonefellow content.

A Collection may contain:

- Tracks
- Releases
- Playlists owned by the user
- Paid/complete purchases owned by the user
- Saved custom-media builds owned by the user

Collections support create, rename/description, delete, add, remove, and manual ordering.

Deleting a Collection does **not** delete the underlying favorite, playlist, purchase, or build.

Collection ownership is enforced server-side. Cross-user playlist, build, purchase, or Collection references are rejected.

## Purchase ownership

My Library only presents order items as owned purchases after the canonical order record is paid/complete. A payment-pending order can still be inspected in My Account, but it does not become owned Library content prematurely.

## My Account

My Account remains the place for profile, subscription/billing, receipts, notifications, security/sessions, and transactional order history.

Saved-content management has been consolidated into My Library. Account includes a compact My Library summary and deep links to Tracks, Releases, Playlists, Purchases, and Collections.

## Agent

The Stonefellow Agent now routes requests such as:

- “open my library”
- “show my saved music”
- “show my collections”
- “show my purchases”
- “show my playlists”

to the appropriate My Library tab instead of sending library requests to Account settings.

## Admin

Admin remains read-only with respect to customer library organization.

Admin analytics now includes:

- Users with saved library content
- Favorites
- Playlists
- Owned purchases
- Saved builds
- Collections and collected-item totals
- Most-saved tracks
- Most-saved releases
- Per-user My Library summary
- Per-user Collection names/item counts

No Admin control was added to casually mutate a customer's personal Collection or saved-content state.

## Database

Section 14 advances the database schema to **1.3.12**.

Migration:

- `2026-10-08-013` — user Collections and mixed saved-item organization.

New tables:

- `user_collections`
- `user_collection_items`

Run **`/upgrade.php`** after deploying v1.3.12.

Application: **1.3.12**  
Database schema target: **1.3.12**
