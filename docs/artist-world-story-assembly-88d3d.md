# Stonefellow 88D3D — Private AI Director Timeline & Story Assembly

**v1.3.117** · database schema **1.3.54** · migration **2026-10-18-045**.

## Editorial workflow

Under **Admin → Agent & Knowledge → AI Production Review**, the new **AI Director · Editorial Assembly** section provides a private production timeline. Create an assembly with a story premise; choose verified visual scenes, verified dialogue/transcripts and songs from Stonefellow's **existing canonical music catalog**. Reorder clips, adjust measured trims and placement durations, add editorial notes, and save an explicitly versioned timeline. Another editor's saved revision cannot be silently overwritten.

Approved-for-edit captures alone are not sufficient for evidence: timeline source scenes and transcripts must be **independently verified**. Machine-generated draft observations must first complete the separate 88D3C editorial workflow. A referenced source that loses verification is flagged and cannot be saved in a revised assembly.

Music sources are referenced using canonical catalog track IDs, not duplicate shadow music records, file uploads or ad hoc URLs. Adding a song to an assembly **does not itself grant synchronization, master-use, public performance, mechanical or publishing rights**. All music licenses remain separate from contributor publication consent.

## Director guidance and shot requests

Rules-based, read-only Director assessment proposes missing visual coverage, one dialogue beat, and a Stonefellow music reference, and suggests opening/closing visuals. It reports current contributor **publication** clearance separately from editorial verification. A deliberate editor action can turn a missing-shot suggestion into a **private draft** through the existing governed capture-request API, targeted to a contributor role. The request stays unpublished until separately reviewed.

This is **not a generative story writer, not a video renderer and not an AI-provider call**. It is the secure structural foundation for those future services.

## Safety and limits

- Production-editor role is required for all operations; Admin HTTP writes require normal session and CSRF protection.
- Private database tables `story_assemblies` and `story_assembly_items` store revisions, ordered source-bound intervals and notes. No public media URL, raw source file, provider credential or downloaded video is emitted.
- Maximum 50 segments and one-hour total assembly duration; each segment may be up to ten minutes.
- Verified scene trims must fall within the verified annotation interval. Still-image source timecodes remain zero, with a distinct editorial screen duration. Transcript trims must lie within verified segment boundaries. Canonical music track IDs must exist, with a known duration and valid trim.
- Every save requires the current revision number and is atomic with its activity audit, avoiding partial timeline saves or stale multi-editor overwrites.
- Missing publication permission is reported in the editorial timeline. **No public rendering, exporting, publishing or external model processing is enabled.**

## Deployment and validation

Backup the deployment and run `upgrade.php` to apply migration 045. Automated SQLite regressions cover role isolation, genuine catalog track references, verified source boundaries, ordering, revision conflicts, audit rollback, dynamic clearance warnings and deterministic Director coverage. The real MySQL 8 CI checks verify new timeline tables. Full HostGator/browser certification and licensing review remain pending.

**Recommended 88D3E:** supervised AI story composition proposals driven by verified timeline metadata only, with per-scene suggestions, music synchronization license checks, revisioned editorial approval, explicit provider egress controls, and a separately governed render pipeline.
