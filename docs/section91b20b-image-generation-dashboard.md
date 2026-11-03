# Section 91B20B — Admin Image Generation Dashboard (v1.3.228 candidate)

## Delivered

- A unified Admin Image Studio dashboard: all-time request counts, statuses, review queue, last-30-day model activity and provider-reported input/output usage, plus UTC daily attempt allowance.
- Seven built-in visual presets: album/single cover, song moment, Artist World environment, social promotion, artist portrait, merch illustration and website hero/background.
- Authenticated Admin creation and archival of **custom** presets. Built-in presets are immutable and cannot be archived.
- Editable scene brief and prompt composer. **Apply preset locally** generates a draft prompt with no provider request. **Ask Stonefellow Agent** explicitly sends only the Admin-entered scene and preset guidance to the existing configured text-model provider; the model produces *text only*. The result is not automatically saved, approved, or submitted to the Images API.
- An Admin Agent Chat handoff for natural-language image creation requests. Its parser returns the creative brief and preset selection for manual review in Image Studio. This chat route performs no remote paid generation and no database writes beyond the normal chat transcript.
- Main Admin dashboard image review stats and navigation.
- **Media Library:** “Create with AI” and “AI generated” filters, AI provenance labeling. **Catalog track artwork** and universal media editors (release, Story, site, campaign, merchandise, etc.) can open Image Studio with an editable context-specific visual brief. The generated asset still comes back through the existing single Media Library and its existing attachment/publishing controls.
- Preserved 91B20A approval steps: save immutable prompt → approve exact prompt → confirm paid request → inspect private generated image → approve the asset → separately attach/publish.

## Privacy, permissions and cost boundaries

1. All new server endpoints require the existing Admin authentication and CSRF write controls.
2. Agent Chat never publishes, generates or submits a paid image request automatically; visual briefs are **local proposals**.
3. Optional Agent prompt refinement is a separate, explicit, billable **text** API call. The UI explains this before the call. Server-side usage ledger tags it `image-prompt-composer` instead of `image-generation`.
4. Only the manually entered concept and selected visual guidance are sent for prompt refinement. Private catalog, KB, CRM, listening history, recorded conversations and Admin operational context are **not automatically forwarded**.
5. Generated image assets retain the media guard from 91B20A. Unapproved images cannot be attached or made public by older Media Library functions.
6. The dashboard never invents a dollar cost or converts paid API images into a fixed Active Token rate. Actual external billing is checked in the provider organization.
7. Existing users, payments, media, published songs, Stories and release data are preserved.

## Scoring and gates

| Section | Local verification | Remaining live gate |
| --- | --- | --- |
| Visual preset records and archival | SQLite schema/uniqueness/status tests | MySQL creation, seeding and archival on staging |
| Image operations dashboard | Static API/UI checks | Live PHP queries with real historical records |
| Agent refinement | Source and boundary checks | One explicitly authorized paid text-model call, usage ledger check |
| Admin chat handoff | Parser/presentation and no-image-call checks | Full chat + popup interaction |
| Media Library + artwork integrations | JS checks, module wiring, Media guard tests | Full UI visual/mobile and publishing permission tests |
| Regression baseline | Node suites | PHP runtime checks and HostGator staging |

This is a **candidate**, not a certified 10/10 release. PHP CLI and a staging provider session are not available in the recovered workspace, and no live paid provider call was performed. There is no Git metadata in the recovered snapshot, so no PR or merge is attempted.

## Suggested follow-ups

1. **91B20C — Approved image placement:** A governed preview of track artwork, story scene, campaign and site hero proposals side-by-side with current media; human approval before changing any link.
2. **91B20D — Reference image and variations:** Add consented uploads/references, persistent style identity, safe visual revision chains, and duplicate-cost controls.
3. **91B19D — Token escrow:** Extend the v1.3.227 voice reserve/settle slice to every paid text and background-worker action; add stale-hold/manual reconciliation policies.
4. **Actual dollar costs:** Record currency-specific API billing from confirmed provider pricing or billing exports, rather than guessing dollars from image token counts.
