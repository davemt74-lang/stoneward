# Stonefellow 88D3G4H — Governed Private Revision Restoration

**v1.3.130 · Database schema 1.3.64 · Additive migration 055**

G4H extends the immutable historical review system with a strict, **three-action restore proposal**. Editors can request an earlier archived revision, independently document a human approval or rejection, and finally apply an approved request. A request or approval never edits the current timeline. An apply creates a **new saved draft revision**, not a destructive rollback or replacement of the historical snapshot.

## Exact gates

Every request is bound to the original assembly, its exact current revision, target historical revision, and SHA-256 snapshot digest. The snapshot is read with its integrity check and every candidate clip is revalidated against current verified scene/transcript/catalog references and source trim limits. A human must record a meaningful rationale (25+ characters) to request and an independent review basis (25+ characters) to approve or reject. A separate explicit apply action again rechecks the immutable snapshot, live source rights and current optimistic revision. The restore uses the existing `sf_assembly_replace` transaction to write clips, rearchive the new version, and record audit activity.

The approved request ledger is consumed **inside the same database transaction** as the restored sequence, so a failed audit, stale concurrent revision, revoked source, or invalid reference cannot create an unapplied-orphaned timeline or silently consume approval. Unique source/target revision requests prevent duplicate pending work and approval reuse. A rejected request cannot be applied. Rejected requests remain rejectable even if their source rights have since been revoked.

## Strict non-effects

No raw media or source filesystem paths are loaded from history. No AI provider is called. No footage is exported or published. Historical sync/master licenses, music-binding settings, FFmpeg render approvals, QC outcomes and other revision-specific permissions are **not copied** to the new revision; these must be reverified explicitly. Existing AI Director proposal decisions appear alongside restoration activity, but the agent has no authority to restore without a human reviewer and a separate apply operation.

## Verification

`php tests/section88d3g4h-governed-restore.php` reuses real verified-source fixtures to exercise contributor isolation, duplicate prevention, current-source revocation, stale revisions, rejected approvals, apply-once semantics, audit failures and transaction-wide rollback. `php tests/section88d3g4h-release.php` locks the migration, version identity and protected API/studio/CI features. The full MySQL/FFmpeg/composer and previous section regression suites remain required.

Live HostGator installation, actual human production review and field licensing verification are still needed; the checks certify software behavior, not legal rights ownership.
