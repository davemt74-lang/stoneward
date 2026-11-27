# ML2C — Import Music from VP3

## Scope and workflow

Stoneward connects one independently hosted artist installation to an existing VP3
Music Workspace site grant. VP3 hosts selected canonical recordings. Stoneward
retains its catalog IDs, presentation, local editorial changes, player, playlists
and listener privacy.

The provider protocol is **vp3_music_provider_v1**; this client's management
responses use **stoneward_vp3_music_v1**. The original v1.3.332 acceptance pinned
[software@41603520b24c](https://github.com/davemt74-lang/software/tree/41603520b24c3ac6146dca5cb7310d04a8a3de9e).
[ML2B's implemented contract](https://github.com/davemt74-lang/software/blob/41603520b24c3ac6146dca5cb7310d04a8a3de9e/docs/music-listening/ML2B-IMPLEMENTATION.md)
owns provider authority, timing, media and receipt semantics.

Version 1.3.333 adds the bounded public-page profile used by VP3's ML3 reporting.
Its paired workflow pins the published extended provider; a separate HTTP run
retains compatibility with the original provider above. The release PR records
the exact source pair and deployment ZIPs. No wire-protocol version changes.

Normal Admin Catalog and Music & Media navigation expose **Import Music from
VP3**. The artist registers this site in VP3, saves the verification JSON and scoped
credential in Stoneward, verifies the site in VP3, then checks the connection in
Stoneward. This is guided site setup. The backend credential cannot impersonate
the artist account or administer VP3 site registrations.

The selection view supports song-title search, pagination and explicit review of
the full selected set. Whole-album selection appears when the complete unfiltered
catalog is loaded. Selections outside a filtered/page view remain explicit and
removable. A zero-song import explicitly removes the site's provider selections.

## Private connection and safe transport

The management endpoint uses current administrator authentication and normal
write-CSRF enforcement. Connection writes carry a UUID request identity and
expected connection revision. Exact retries return recorded results; conflicting
reuse fails.

Credentials use the existing application encryption facility with the separate
config/app-secret.php key. Private state, receipts, baselines and publication
journals stay in storage/vp3-music. Public status never returns the credential;
the UI never stores it in localStorage, sessionStorage or a URL. The public proof
endpoint serves only the configured vp3_music_site_v1 response.

Server transport accepts a canonical HTTPS public DNS provider on port 443,
including a supported VP3 installation subpath. DNS answers must be public and the
chosen address is pinned for certificate-verified TLS. Redirects, proxies and
netrc credentials are disabled. Requests, responses and transport times are
bounded. Only a trusted PHP caller can supply a fixture transport dependency;
HTTP fields and environment settings cannot enable it.

Stoneward's initial profile requires its configured HTTPS domain root to match
the verification origin. The fixed root proof route makes subdirectory installs
an explicit unsupported setup in this phase.

## Management API

The endpoint is admin/api/vp3-music.php. Responses are JSON, private/no-store, and
use safe public error codes/messages.

| Operation | Request and result |
|---|---|
| GET status | Safe connection, catalog hash, pending import and last receipt |
| POST connect | Request UUID, expected revision, provider URL, credential and verification object |
| POST disconnect | Request UUID and expected revision; removes local connection authority |
| POST catalog | Offset and title search; safe items, selected IDs, selection revision and next offset |
| POST import | Request UUID, expected connection/selection revisions, catalog hash and full selected ID set |
| POST resume | Original pending request UUID and expected connection revision |
| POST discard | Pending request UUID, expected revision and explicit confirmation |

Public source resolution binds a published local track to the current configured
provider installation/site/recording. It returns only the safe endpoint and typed
reference. VP3 still enforces current authority on requests and delivery.

## Catalog identity and recovery

Import identity is the provider installation plus canonical recording, independent
of the current credential. Reconnection and reimport preserve local track IDs.
An unrelated recording with a colliding ID cannot be overwritten.

Imported public metadata is allowlisted. Baselines let later imports update
unchanged provider-supplied values while retaining local editorial overrides.
Local visibility/archive/trash state is preserved. Unavailable remote references
remain distinct from native recordings and are not silently deleted or converted
to local audio.

The public catalog carries only fixed typed audio_source references and safe
availability. It never contains management credentials, visitor keys, expiring
media URLs, journals or private provider metadata.

An import journals its frozen provider selection request and staged catalog.
Provider selection, local JSON and generated public JavaScript are not a single
distributed transaction. Resume checks exact staged/current hashes and current
connection revision. Unrelated local changes produce a conflict rather than a
lost update. Discard abandons local pending work; the next review reconciles the
current provider selection, which may already have changed.

Streaming imports supply no commerce rights. Projection and actual cart/custom
media, vinyl, HomeServer master/download, campaign-download and manufacturing
admission reject typed VP3 sources even with falsified commercial/master fields.

## Shared player and listening

A reusable typed-source adapter extends the current player. A deliberate new play
creates a UUID and independent in-memory provider key. Begin allocates a session
with zero heard time. The first actual playing transition reports zero-time resume.
Ordered events remain frozen until acknowledged, preserving identities on retry.

Renewal changes the media transport URL for the same session and pinned asset.
Position, rate, playing/paused intent, sequence and counters continue without the
main player's new-track branch. Late responses, cancellation, blocked autoplay
and terminal revocation have explicit lifecycle handling.

If bounded transport retries cannot confirm provider access, playback pauses and
automatic retries stop. The player retains its frozen renewal/event identities;
explicit Play must confirm the same session before audio can resume. A browser may
hide a denial behind a CORS/network error, so the client reports confirmation is
required without inventing a specific revocation reason. Readable terminal denials
remain terminal.

Stoneward personal history retains its own privacy settings. Provider reports
receive no customer ID, email, account cookie or private history. Downloaded bytes
are not listened time.

Main/footer and supported Agent/canvas playback use the same source capability.
Host-owned independent canvas players represent distinct observed plays.
Provider raw getAudio/blob retrieval is declined; arbitrary remote URL access
and sandbox network policy are not broadened.

## Attribution and phase boundaries

Only actual recognized public HTTPS page paths are sent, without query/fragment
data. Private and unsupported paths are omitted before transport. Version 1.3.333
adds the actual public /track/{id}, /release/{id} and /stonefellow-v120.php routes.
Track/release IDs use the Apache router's bounded alphabet, with at most 160
letters, digits, underscores or hyphens. Raw paths are checked before browser URL
normalization so traversal, encoded separators, controls and backslashes cannot
become an accepted public route. Browser attribution stays on the actual page origin.

The extended provider advertises public_music_pages_v2. An older provider still
accepts the playback request but retains registered-site and recording attribution
with unavailable page context for these new forms. Either deployment order is
compatible. A provider update preserves exact pending begin receipts that used the
old omitted-page projection, without changing their historical source. No invented
/music source is substituted.

Site origin is verified; retained page context is client-reported. This is not
proof of a unique person, human attention, paid entitlement or royalty obligation.
The initial profile has one connection, explicit sets up to 500 public recordings,
canonical /uploads assets and VP3's 64 MiB limit.

## Acceptance and release

The ten scoped criteria are current admin authority; private connection/proof;
explicit selection and stable IDs; recoverable publication and retries; public
metadata/commerce isolation; shared playback; bounded event reporting; same-session
renewal/revocation; usable desktop/phone controls; and verified source/release.

The implementation PR records exact source commits, actual command results,
browser artifacts, merge and package verification. Tests exercise real paired PHP
providers and production clients. Authentication/unrelated-surface adapters are
identified explicitly. Fixtures do not certify deployed DNS/TLS/Apache/CDN behavior,
restored production backups or physical devices.

Follow [deployment and recovery](VP3-MUSIC-DEPLOY.txt). ML2D covers the actual-host
and physical-device pilot. ML3 adds listening dashboards; earnings, payments and
managed hosting retain their own phases.

The full source update excludes private host configuration, the encryption key,
runtime data and the generated assets/js/catalog.js paired with the host catalog.
Deploying it must preserve the existing artist's catalog and private connection.
