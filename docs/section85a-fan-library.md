# Section 85A — Purchased Music & Vinyl Print Rights

## Existing systems reused
The Stonefellow website already has paid-order and digital entitlement grants,
per-song vinyl-print balances and immutable package prices, My Library, a persistent
music player, and a private Media Library master resolver. 85A reuses those
without building a second checkout or changing HomeServer.

## Website improvements
- My Library now has an **Owned Music** tab distinct from saved/favorite tracks.
- The first-party `fan-owned-music.php` endpoint filters to this authenticated
  account's verified PAID track and album grants, returning sanitized titles,
  playback eligibility, a master-publication indicator and vinyl credit balances.
- The UI shows granted, reserved/used and available print licenses for each song.
  Additional print licenses open the existing track product and checkout pathway.
- `fan-owned-download.php` validates the current Stonefellow session, rechecks
  paid ownership on every request and streams a private, integrity-verified MP3
  or WAV. It does not return private paths or raw reusable bearer tokens.
- Storefront player remains unchanged. Digital streaming/download does not
  consume vinyl print credits, and each record pressing requires one credit
  per song.
- The responsive mobile layout follows existing site typography/colors.

## Limitations / reality checks
A catalog preview or a file absent from the Media Library is NOT a downloadable
master. The demo catalog's `assets/audio/*.mp3` files are not deployed by
this source repository. Administrators must publish valid private media
masters using existing Media Library tooling before owned downloads work.
The download endpoint fails closed when storage files or ownership are missing.
Live HostGator cookie, storage, playback, download, and payment checks remain
a separate deployment acceptance step.

## Acceptance
`tests/section85a-site-ownership.php` checks paid track/album grants,
non-owner and unpaid exclusion, independent vinyl-only rights, per-song
reserved credit balances, package prices, publisher readiness, path traversal,
and first-party authorization. Dedicated 85A CI runs PHP lint, JS syntax and
wallet regressions. Full release acceptance on the ready-for-review PR and
merged main must pass before shipping.
