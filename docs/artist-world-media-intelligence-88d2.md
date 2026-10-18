# Stonefellow Artist World — 88D2 Media Intelligence (v1.3.112)

## Purpose
Evolve Creative Capture into a private, reviewable production-evidence system.
Stonefellow only; there are no HomeServer or Chrome-extension changes.

## Shipped scope
- **88D2A:** Technical evidence: server-observed SHA-256, MIME, byte size and available image dimensions, immutably linked to a private capture and Media Library asset.
- **88D2B:** Human or machine-draft timecoded transcript segments, speaker *labels* rather than identities, explicit editorial verification.
- **88D2C:** Human or machine-draft still/video scene descriptions, locations, tags and millisecond ranges, all requiring editorial verification.
- **88D2D:** Private editorial character, location, event and theme graph with evidence provenance links. Character records are fictional/story concepts, not verified real-person identities.
- **88D2E:** Approved-evidence search in the Admin Agent Brain; excludes unreviewed submissions and unverified transcript/scene entities, includes source IDs and release status, with no public media URLs.
- **88D2F:** Coverage-gap recommendations for video, dialogue, environment photos and ambient audio; human-triggered draft creation with persistent duplicate prevention. Drafts are not published.
- **88D2G:** Coordinated release version, upgrade migration 040 / schema target 1.3.49, and SQLite/real MySQL acceptance.

## Deployment
Run the existing `upgrade.php` migration workflow after taking a verified backup. The migration installs the production-evidence tables idempotently. Do not overwrite HostGator private configuration, existing storage, account records or media originals with an application-source ZIP. Preserve existing Media Library ACLs. Use the full deploy manifest to identify the exact source commit and checksum.

## Governance
Uploaded media, drafts and contributor identity remain private. Transcript/scene annotations are never treated as likeness releases, final permissions or publication. Editorial verification does not confer publishing rights. User media ingestion still requires rights attestation and per-role quotas, and machine-origin requests remain private until an authorized editor opens them.

The Agent Brain receives only bounded, editor-authorized production search results in a read-only route; no raw media URLs or private documents are passed to external models by 88D2.

## Not shipped yet
- Live speech-to-text/diarization jobs, automatic vision or scene detection, face/actor recognition, real-time transcribing, semantic embeddings, AI screenplay generation, editorial timeline assembly, soundtrack licensing or final video rendering.
- On-device phone capture and live HostGator field certification remain outstanding.
- The 88D2B/C APIs provide structured, reviewable inputs for later opt-in model workers; they do not claim model execution.

## Next technical phase
**88D3: governed inference workers** with resource budgets, model/provider selection, consent-aware media processing, explicit usage costs, queue/retry/recovery, protected preview thumbnails, automatic speech-to-text and video keyframe descriptions, all feeding the existing 88D2 drafts. Never infer a verified identity from a model label. Publish only through authorized editorial release workflows.
