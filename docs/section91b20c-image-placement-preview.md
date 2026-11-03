# Section 91B20C — AI Image Placement & Preview (v1.3.229 candidate)

## Delivered

- **Admin Image Studio**: the review queue adds “Preview placements” for approved generated images. Private, unapproved, and failed image jobs cannot reach the placement screen.
- **Seven placement previews**: song cover, release cover, Story hero, timecoded Song Moment photo, campaign hero, site hero, and site canvas background. These all reuse the Media Library and existing editorial content, rather than creating another gallery or Story system.
- **Before/after comparison**: reads current Media Library attachments for the chosen existing song, release, Story, campaign or public site and displays a local proposed image beside the current image. Square, portrait, wide, and orb-led Song Moment framing; desktop/mobile toggle.
- **Governed private staging**: Admin selects an existing target and checks a disclosure explaining that nothing becomes public. A second confirmation performs a CSRF-authenticated write. The server rechecks image approval, image category, allowed entity/role, and target existence, then creates only a private, non-downloadable, non-featured Media Library attachment. No primary image is replaced and no story, campaign, website or song moment is published automatically.
- **Existing-attachment safety**: a repeated private staging request reports already staged. A placement that already publicly references the same image is not downgraded to private or misreported as newly staged.
- **Media Library entry point**: AI-generated asset cards can open the placement preview, including for older image jobs not in the 60-item dashboard history. The lightweight lookup exposes only job ID, approval status and asset UUID.
- **Content-editor handoff**: the placement dialog offers a shortcut to the existing Catalog, Releases, Stories, Song Moments, Campaigns or site Settings editor. Those separate editors retain their own publication controls.

## Deliberate boundaries

1. No additional image API calls, text LLM calls, token consumption or paid retries are caused by previewing or staging.
2. A generated image must have completed both the saved-prompt and image-asset approval gates before staging.
3. Site canvas placement is an **attachment**, not an automatic switch of the public Agent Canvas background. Song Moment staging similarly does not create a public moment; the image must separately become eligible as a public Media Library photo and be chosen at a timestamp in Song Moments.
4. The server validates existing target IDs to prevent orphan attachments created through this workflow.
5. Existing music, Stories, moderation history, campaign records and Media Library assets remain intact.

## Quality scoring (local candidate)

| Area | Local result | Live requirement for 10/10 certification |
| --- | --- | --- |
| Placement presets and current-vs-proposed comparison | Source regression checks green | Verify seven screens with real site/media assets |
| Approval and private-stage boundaries | Server source checks green, automatic publishing absent | PHP/MySQL staged-attachment integration tests, conflict cases |
| Admin Media Library handoff | Source wiring and historical job lookup checks green | Open asset cards and stage/inspect an older approved job |
| Responsive modal, keyboard operation | Source/CSS and JS syntax checks green | Visual desktop and iPhone test; screen reader/focus trial |
| Regression | Node regression suite green | HostGator test deployment, cURL/PHP/database verification |

This is a **release candidate**, not a certified production 10/10. No PHP executable or live HostGator staging database was available in this workspace. No paid provider request was made. The recovered source does not contain Git repository metadata, so no PR or merge can be performed here.

## Suggested 91B20D

- Introduce **reference-guided variations** with explicit consent and permission to use uploaded photos, revision lineage, and cost-aware approval for each paid iteration.
- Add a visual placement-diff receipt (before/after snapshot metadata, actor, target, preview revision) and more direct editor-specific preview handoffs after the existing publish safeguards are tested.
- Reconcile actual OpenAI image spending against billing exports rather than estimating dollar charges from token counts.
