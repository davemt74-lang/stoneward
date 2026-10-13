# Stonefellow Digital Player Platform — Product Roadmap
Updated 2026-10-12 | Source baseline v1.3.55 | Desktop sideways turntable CSS release v1.3.56

## Non-negotiable player layout
- Desktop record-builder's digital turntable rotates **45 degrees counterclockwise** as of v1.3.58.
- The physical **bottom** edge points toward the lower-right viewport and approximately one quarter of the sideways player extends beyond the right viewport.
- Use the desktop stage only at 1024px+; the mobile builder stays in its existing layout.
- Keep roughly 75px breathing room above/below the rotated, screen-height player.
- The record spins on real audio playback only; track artwork, title and Side A/B continue to update.
- Separate physical player orientation from record spin animation, so buying parts does not break rotation.

## Planned subsequent sections (not implemented by this release)
1. **Per-user Player Identity & Library:** user-specific player designs, separately owned parts, active design, saved presets, multiple player instances and account-level sync. Keep visual player config and purchase entitlements distinct from music collections.
2. **Visual Player Parts Studio:** Admin-controlled 2D part layouts with arbitrary safe base outlines/shapes (not only color themes), skin art, material presets, component anchoring, rotating platter zone, tonearm positioning, clipping boundaries and drag handles. Publish designs to the module marketplace. Avoid arbitrary executable user-uploaded code.
3. **Real audio-processing effect packages:** Web Audio-based EQ, adjustable playback speed, pitch/time stretching where supported, optional analog processing, A/B loop and visualizer. Plugins are capability-scoped, versioned, compatibility-tested and can be removed without invalidating bought music. Validate CPU load, clipping, headphone/browser variations, mobile compatibility, volume safety, accessibility and performance; avoid pretending that CSS-only effects alter actual sound. Require admin approval of premium DSP designs.
4. **Managed front/back vinyl artwork templates:** Admin Media Library uploads; separate front, back, center-label and insert layout; stock template library; safe typography and personalized custom note; publish/unpublish/version history; commercial-print assets; rights and preflight checks; preserve historical purchased records and user exports. Present front/back previews in customer record builder.
5. **AI Song Studio:** multi-provider adapter supporting new compositions versus source-preserving remixes. Cost quotes per request, rights/authorization flags per song and master/stem, paid checkout or reserved credits, async tasks with durable idempotency, queue/progress, cancel/retry/refunds, consent, retention/privacy rules, user-owned output library, and admin review before any public release. Distinguish newly generated inspiration from a legally authorized arrangement/remix. Do not infer music rights from a song's presence in a catalog.

## Candidate providers to evaluate with official API sandbox/terms
- **ElevenLabs Eleven Music**: generate new compositions, vocals/instrumentals, song sections, licensed offerings; audio references are creative influence and **do not** preserve/reproduce a source song's arrangement.
  - https://elevenlabs.io/docs/api-reference/music/compose
  - https://elevenlabs.io/docs/overview/capabilities/music
- **Stability AI Stable Audio 3**: audio-to-audio transformations and inpainting; validate fidelity on approved Stonefellow masters before selling remixes.
  - https://platform.stability.ai/
- **Mureka**: uploaded-audio remix generation and stem/track workflows; requires provider-specific input, output and rights reviews.
  - https://platform.mureka.ai/docs/api/operations/post-v1-song-remix.html
- **Google Lyria 3 Pro**: secondary/new-composition engine; verify commercial release terms for each integration tier.
  - https://cloud.google.com/blog/products/ai-machine-learning/lyria-3-and-lyria-3-pro-on-vertex-ai/

## Acceptance gates
- No phase is marked 10/10 until code review, security/integration tests, public/mobile browser review, passing PR release gate and merged-head certification.
- Deliver SHA-256 checked website deployment ZIP from the certified main commit.
- A production payment that is not settled cannot unlock paid modules or generated outputs.
- Capture original uploads and generated results as separate files with provenance, authorization flags and track-specific usage permissions.

## Section 58 — builder workspace refinement (v1.3.58)
- Global site header only above the full-width builder; the Agent orb, idle status, and spoken message no longer occupy a separate top row in this view.
- Left control rail (360–440px) starts with Vinyl/Cassette then Saved Drafts, Cover Design, Side A/B, Add Song, player add-ons and Checkout/Save/Export controls.
- Edition name and stock colors remain editable inside Cover Design rather than a hero section.
- Right turntable chassis sits on the oversized, clipped player stage at **45° counterclockwise**, bottom pointing down/right. Its record platter animation remains independent of the chassis transform.
- Existing mobile view is stacked; page bottom reserves sticky composer space. Existing real DSP, premium effects, managed Admin front/back artwork and paid AI generation remain future sections.

## Section 60 — Five-tab vinyl builder and true 90° player (v1.3.60)
- Builder format switch (Vinyl/Cassette) retired: current builder supports vinyl only; historic cassette orders and saved draft records remain stored.
- Left rail consists of five accessible, keyboard-operated tabs: Saved Drafts, Album Artwork, Song Lists, Personal Notes and Submit. Each section has one associated tab panel; tab changes never reload the page or stop music.
- Album Artwork contains edition title, theme, front/back sleeve selections, and optional paid turntable parts; the personal dedication moved to Personal Notes.
- Song Lists owns Side A/B, drag/drop sequencing, fill controls, catalog search and per-song previews. Submit owns quote/cart action, draft saving, export and start-over.
- Physical deck rotates 90° counterclockwise; its bottom points to the right screen edge, with a responsive stage fit and independent record spin.
- Vinyl order quotes now include server-validated front/back artwork and custom note (280-character cap), and user-saved drafts keep their original account data.
- Future roadmap still includes fully managed Admin-uploaded front/back artwork templates and real audio DSP effects, in addition to per-user player profiles and AI-powered music generation.
