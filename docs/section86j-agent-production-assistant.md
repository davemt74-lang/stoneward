# Stonefellow Section 86J — Agent Brain Production Assistant

## Architecture

The assistant is a new panel **inside the existing Admin Stem Studio canvas**, not a separate chat, mixer, public AI connection, or HomeServer feature. It uses the authoritative private project manifest and the Admin-only Agent Brain scope.

- Private read endpoint `admin/api/stem-advisor.php` authenticates Admin and only accepts GET. It does not modify projects or expose media streams.
- `api/stem-production-advisor.php` builds a bounded snapshot of 64 tracks / 32 buses, counted sends and automation, the order and browser support status of FX chains, saved mix count, and 86G mastering evidence. Native RPP, VST chunks, audio URLs, source paths, fan records and keys are never included.
- The deterministic issue engine points to exact existing facts: missing linked stems, unsupported native processing or receives, stale measurements, clipped samples, sample peaks, stereo imbalance, muted tracks, and reference low/high-band differences.
- When and **only when** a current, supported, complete render has sample peaks above the -1.0 dBFS caution threshold and lacks a competing master-volume envelope, it prepares a conservative, bounded master-volume automation lane. It does not apply it. All other uncertain changes, including vocal EQ, reverb, pans, vinyl cutting and catalog publication, remain recommendations for human listening/verification.
- The Assistant panel can transfer this exact draft to the existing Section 86F governed workbench, which validates project revision and every point, records a reviewed Agent proposal, requires owner-typed APPROVE over the exact payload hash, then separately executes once. No new privileged executor was added.
- Admin Agent Chat, under the existing `stem_mix_review` route, receives a small redacted project summary, can optionally ask the configured **Agent Brain** LLM for an *advisory explanation* using `sf_ai_llm_candidates('agent_brain')`, and falls back to deterministic facts if the provider is unavailable. LLM output cannot invoke tools or grant authorization.
- The public and customer Agent Brain contexts do not gain production-data access, production proposals, permissions, media, or knowledge of unreleased songs.

## Use

1. Open a Stonefellow song under Admin Catalog, and open its existing REAPER Stem Studio.
2. Import stems, configure mix routing, FX and automation, then render and save 86G measurements.
3. Select **Analyze this project** in the **86J Agent Brain Production Assistant** panel. Read the diagnostics and (if present) review a suggested bounded gain reduction.
4. Select **Discuss with Admin Agent Brain** to receive the admin model's evidence-bound explanation. The UI keeps the current Stem Studio visible.
5. If a proposal is provided, select **Review exact change in Agent Brain**. Review the actual lane and hash; only type APPROVE and execute if you choose.
6. Re-render and compare before progressing to 86H readiness and 86I protected Media Library publishing.

## Evidence limits and guardrails

Audio analysis is browser-measured, not server-certified or native REAPER plugin fidelity. Track-level timbre, mixing quality, vocal resonances and noise cannot be inferred from metadata alone. Reference tonal ratios are rough estimates, not automatic EQ prescriptions. User-entered track names are shown only in the private Admin canvas and are **not** sent in the optional LLM brief. Never claim an Agent edit happened unless the existing ledger completed it.

## Tests and release process

Run focused 86J PHP/JS acceptance, 86F proposal lifecycle, 86G/H/I mastering and publishing, and 85B protected masters. Require exact-revision full release gate and MySQL verification; merge only green and verify merged main. Deliver full-site ZIP, SHA-256 and report. No HomeServer code changes.
