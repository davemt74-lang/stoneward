# Stonefellow 88D3G4F — Precision Editing & AI Director Refinement

**Release v1.3.128 · Schema 1.3.62 (no migration)**

The private editorial timeline now supports non-destructive source trim nudges (100 ms precision), bounded edit-session undo/redo (40 checkpoints), and a virtual storyboard playhead synchronized with the exact EDL output including overlapping per-cut audio/video transitions. Still-image source timecodes remain immutable; editors may continue adjusting placement duration.

The existing supervised **OpenAI/Anthropic story composer** can optionally suggest trim-in/trim-out refinements for each known ordered clip. Its constrained JSON validator now permits only nonnegative integer multiples of 100 ms, at most 500 ms on either end, never trimming the verified source below 500 ms. Backward compatibility is retained for model outputs that do not contain trim fields. No new model calls, providers, billing or permissions are created by staging.

AI proposals must first be explicitly approved. Trims can be staged *locally* only when the approved source ordering is identical to the current revision, or when that previously approved ordering has already been applied to the matching revision. The Studio independently revalidates the suggested trims, skips image-source timecodes, asks an editor to confirm, and never saves on behalf of an AI provider. The editor reviews each proposed source in/out time and explicitly **Save timeline revision**; that server action still validates original source bounds/rights and enforces optimistic revision concurrency. A new timeline revision intentionally expires prior per-revision music/master/render permits, which must be rechecked before rendering.

Existing source SHA-256 certification, sync/master licensing, exact per-cut transition policy, private CLI-only FFmpeg render and human video approval remain unchanged. No publication or automatic media transfer.

Automated certification: `php tests/section88d3g4f-refinement.php` and the full legacy Composer/G1–G4E FFmpeg + MySQL release workflows. Browser field verification on real captures remains required.
