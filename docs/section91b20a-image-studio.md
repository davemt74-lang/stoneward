# Section 91B20A — Admin AI Image Studio Foundation (v1.3.227 candidate)

## Architecture

The Admin Image Studio reuses **Admin → AI Providers → OpenAI** and its encrypted API key. There is no duplicate key store or client-side provider credential. The feature is opt-in and disabled by default.

One image request follows this manual workflow:

1. Admin saves a textual concept prompt as a `draft`, with fixed size/quality and the current Admin image model.
2. Admin explicitly approves the **saved immutable prompt**: `draft → approved`.
3. Admin explicitly confirms a paid generation. A database compare-and-set and UTC-day lock enforce no parallel duplicate execution and a global daily generation cap. `approved → generating`.
4. Server calls the OpenAI Images generation endpoint with `gpt-image-2.5-flare`, `gpt-image-2.5-sunburst`, or `gpt-image-1.5`. Moderation uses `auto`; a stable HMAC safety identifier avoids transmitting the admin's email.
5. Server validates returned base64 image bytes, MIME and dimensions, then registers the image in existing **private** Media Library storage. The job becomes `needs_review`.
6. Admin inspects the private preview and explicitly approves it as `ready`. The existing Media Library attachment and track-artwork publish functions also check this approval, so an unapproved AI image cannot be linked through those older paths. Nothing publishes or attaches to a track, Story, release, campaign, or website without a later governed Media Library action.

The API is `admin/api/image-generation.php`, backed by `api/image-generation-core.php`. The responsive Admin UI is `admin/assets/image-generation.js` + `.css`, accessible from the main navigation. The Admin Agent may navigate there, but does **not** execute paid generation autonomously.

## Token and cost handling

- Image Generation is a separate feature category in the 7/30/90-day User Management usage reporting.
- For this Admin-only foundation, the unlimited Admin allowance remains zero-charge in Stonefellow Active Tokens. The provider-reported input/output token fields (when supplied) are recorded in the usage ledger and job history.
- API organization billing is independent of a ChatGPT subscription. Dollar cost cannot safely be derived from a fixed tokens-per-image number; the system does not display a fabricated estimate.
- The Admin-configured UTC-day generation cap limits count of paid attempts across all administrators. A failed or timed-out request still counts against the limit, and is **not** automatically retried.
- **91B19D first slice**: Active Tokens are reserved atomically before an authenticated ElevenLabs voice request and settled on successful output. Failed provider requests return reserved credit. An indeterminate result after successful provider response deliberately keeps a hold for manual reconciliation. Existing chat/transcription/background-worker paid routes **do not yet use these reservations**; they must be integrated before claiming whole-platform 91B19D coverage. Reserved versus actual token overages and stale-hold recovery also need dedicated policy before broad rollout.
- User image generation, image editing, reference images, batch generations, dollar-cost snapshots and rate-card billing are out of scope for this first foundation.

## Release scoring and certification

| Gate | Evidence | Score/status |
| --- | --- | --- |
| API-key reuse, no client secrets | Static code checks | Local green |
| Saved prompt / dual human approvals | SQLite status transition test + structural checks | Local green |
| Daily cap and no automatic paid retry | Shared lock/CAS inspection | Local green |
| Image storage / privacy | Media Library reuse and no public attachment checks | Local green |
| Reuse safety | Media Library attachment and track-publication ready checks | Local static green |
| Admin navigation + mobile CSS | JavaScript syntax checks and stylesheet review | Local green |
| Voice token escrow | SQLite reservation simulation and endpoint ordering | Local checks green; PHP live test outstanding |
| Live PHP/cURL and provider response | PHP CLI and staging OpenAI API not available here | **Not certified** |
| Real HostGator file permissions + image review | Requires staging deployment and an administrator's one-off paid test | **Not certified** |
| Real concurrent MySQL execution | Must run on staging database with simultaneous requests | **Not certified** |

Do not score the **release** 10/10 or merge this candidate until those staging gates pass. The prior v1.3.226 accounting changes are retained.

## Suggestions for next rounds

1. **91B19D**: Extend escrow/reconciliation from this first voice integration to every paid chat/transcription/worker call. Add stale-hold repair and post-provider overage policy, followed by concurrent PHP/MySQL testing.
2. **91B20B**: Image history with cost snapshots from current published API pricing, model-specific budget controls, batch variation prompts and a user-facing preview queue.
3. **91B20C**: Reuse generated image assets in existing tracks, releases, Stories, campaigns and site background canvas through reviewable Media Library attachment proposals.
4. **91B20D**: Image editing (mask/reference), consistent characters and artist-world style presets, and agent-assisted **draft creation only** until explicitly approved.
