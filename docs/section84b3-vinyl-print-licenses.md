# Section 84B3 — Finite Vinyl Print Rights, Song Packages & Locked Albums

Stonefellow's **digital music ownership** (streaming and downloadable song/album library) and **physical pressing license** are separate entitlements. A new paid download of an individual track includes exactly **one** print right for that song. A paid digital album grants one print right for **each track** in its immutable purchased tracklist. Each pressed record copy reserves and ultimately consumes one print credit **per distinct included song**. Owning a download forever does not permit unlimited record manufacturing.

## Song packages
The existing Admin Catalog editor now has a per-song field, with syntax `1:2.00, 3:5.00, 5:8.00` (licenses:USD price). Supported package sizes are 1, 2, 3, 5, 10. The price is not invented by the frontend or model and must be set by Admin; unpriced or unlisted options are unsellable. Song pages show the configured packages as add-to-cart options. New `vinyl_license_pack` checkout lines capture immutable track ID, number of rights, and server-side catalog price, without granting streaming or downloading access.

## Fixed album products
Published album releases have Admin-managed `vinyl_enabled`, `vinyl_price_cents`, `vinyl_side_a_count`, and `vinyl_license_packages`. A vinyl-eligible locked edition requires explicit front/back artwork and verifiable original catalog audio masters, and derives an immutable `edition_id` and fingerprint from the canonical tracklist, artwork and A/B sequence. The existing customer release view can add a **digital album**, **locked album vinyl** pressing and extra **album print license packages**; the latter grant the configured number of credits to **every song** in that purchased album snapshot.

The fixed pressing is `album_vinyl` and shares the Section 81 proof, fulfillment and Kunaki publishing contracts. One published Kunaki product can be reused across identical locked album edition fingerprints, but not across different customer designs or revised track/artwork editions. Fixed albums and custom records remain print-on-demand: no stocked physical LP inventory is implied.

## Licensing accounting and safety
- The authoritative grant is recomputed from a real LIVE Stripe paid source order **and** its matching personal Library entry. Unpaid, TEST, simulated, refunded, canceled and other-account orders do not grant a manufacturing credit.
- A digital track line grants one right for that song; a digital album line grants one per frozen track; a paid song license package grants its specified quantity for that song; a paid album package grants its quantity for every frozen song.
- Additional print-license purchases **do not** confer digital ownership. A user still needs a paid original song or album license to build or publish a record.
- Queue staging uses `sf_kunaki_job_enqueue_licensed()` inside the same exclusive lock as HomeServer claims. It counts every active or uncertain publishing job as consuming one print right per song. Two separate pressings cannot spend the same last license.
- Repeating the exact same job ID returns its original reservation without an additional deduction. A different paid vinyl pressing needs separate credits.
- Before HomeServer takes a job, the Cloud revalidates current paid ownership and credit coverage; refunded rights or a now-unpaid pressing make that job **needs_review**, not automatically retried or submitted.
- An unsubmitted, canceled job can release its reservation only through a future governed cancellation flow. Uncertain external outcomes remain reserved to prevent double-use.
- Purchased songs are **never deleted from the streaming/downloading Library** because a print right has been spent.

## Customer license balance
Authenticated customers may open a song or album and see remaining print licenses. The new read-only `api/vinyl-print-balance.php` endpoint returns granted, reserved/used and available credits by song only for the authenticated account. The full-album indicator is the minimum available credits across all songs in that album. Private payment orders, audio masters and worker leases are never returned. It has no impact on streaming or download rights.

## Operational caveats and certification boundaries
No Kunaki products are published or manufacturing orders placed by this change. Actual owner-verified cancellations and vendor receipts require the later HomeServer and Kunaki stages, which are maintained separately. We do **not** grant rights against legacy records that cannot provide matching real paid-order evidence. Stripe live is not enabled merely because this source change merges.

**10/10 engineering gates:** immutable quote pricing, fixed release snapshots, digital entitlements, per-song and album packs, proofed publishing, private rights ledger, one-copy reservation, duplicate/replay control, refund revalidation, and full PHP/JS/MySQL PR+merged-main certification. Do not describe external vinyl publishing as complete until its separately governed provider tests are green.
