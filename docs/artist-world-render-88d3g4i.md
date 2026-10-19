# Stonefellow G4I — Agent Brain Editorial Production Orchestration

**Release v1.3.131 · schema stays 1.3.64 (no migration)**

G4I adds a privacy-bounded, **read-only editorial coordination projection** to the existing administrator Agent Brain. It does not introduce a competing agent, job queue, approval system, or media pipeline.

## What becomes visible

- Recent saved Story Assemblies (maximum eight) with current revision, clip count, human review attention and existing AI Director, restore, and video review status counts.
- A focused, exact-assembly read that collates verified scene/dialogue/music coverage gaps, invalidated sources, publication clearance counts, saved proposal IDs and human status, approved restoration history, bounded revision history, real private render review statuses, and independently checked private render source certification.
- Evidence-bound, deterministic next-action codes: review invalid sources or rights, examine suggested shot coverage, inspect pending human decisions, or inspect private production readiness.
- Private Admin Agent chat: **“editorial production status”** or **“editorial assembly #123”**. The resulting chat text and structured reviewer context come from database reads, not an LLM-generated guess. The existing Agent Brain slide-out panel displays the approval queue and links to the real Production Review/Story Assembly UI.

## Governance

All editorial reads require the same **production-editor permission** as the source assembly. The bridge returns no filenames, raw media, filesystem paths, source IDs, transcripts, contributor identities, suggested creative text, hashed media evidence, or review justifications. It never transfers private Artist World editorial data to an external provider. The Admin Agent’s existing *aggregate-only external-model fact allowlist* is unchanged.

G4I introduces **no automatic workflow actions**. Existing source-rights review, AI Composer approval, restored revision approval, private FFmpeg execution, QC and independent publishing clearance remain in their original tools. The Agent reports status and opens the correct private UI; the editor still decides, approves and executes. Public fan/customer Agent Brain state remains isolated.

## Validation

`tests/section88d3g4i-agent-editorial.php` reuses real approved/editorial G4H fixtures, verifies live proposal/render queues, scoped editor-only reads, source-rights certification barriers, absence of private data and unchanged database mutations. `tests/section88d3g4i-release.php` locks capability identity, unchanged schema, UI route wiring and disallows execution functions in the bridge.

Release CI includes existing schema, real MySQL, media, FFmpeg, Editor, Composer, AI governance and deploy packaging checks. Live browser navigation and HostGator source-rights setup still require field certification.
