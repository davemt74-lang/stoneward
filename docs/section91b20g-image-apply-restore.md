# Stonefellow 91B20G — Reviewed Image Apply & Restore

Release: **v1.3.233**. Extends the approved AI Image Studio and Media Library features from 91B20A–F.

## Admin workflow

1. Open an **approved** generated result in Admin → AI Image Studio → **Preview placements**.
2. Select existing content and device preview. Compare the **current public managed-media attachment** with the proposed image.
3. Stage the approved image privately via existing 91B20C. Staging does not change the public site.
4. For supported primary artwork roles (song cover, release cover, Story hero, campaign hero, site hero), check the **separate public publication consent** and click **Apply approved artwork**. Confirm the exact target.
5. Existing Media Library publishing functions apply the artwork. The previous managed attachment is demoted, never deleted.
6. The editor records a durable Admin audit receipt with target, role, administrator, previous/new links and UUIDs. Recent receipts appear in the placement dialog.
7. **Restore previous artwork** becomes available when the current image has a matching apply/restore receipt with a still-attached previous managed image. Restore requires separate confirmation and creates a new receipt.

## Scope and honest boundaries

- Direct Apply supports **song primary artwork, release cover, Story hero, campaign hero and site hero**. Each uses the authoritative Media Library publishing function.
- **Song Moment photos** remain in the timestamped Song Moments editor; **Agent Canvas backgrounds** remain in the site template/settings publishing flow. They are intentionally private-stage/editor-handoff only; a public media link alone does not create a live moment or switch a canvas template.
- There is no automatic publication when staging, generating, refining prompts, comparing or viewing history.
- A published image outside Media Library (e.g. a legacy external URL) has no managed previous-link receipt. The UI warns that such images cannot be restored through this panel. The content editor remains available for manual recovery.
- Existing Media Library assets are preserved. Restoring changes the public attachment; it does not erase the newer generated asset or its provenance.
- This feature cannot approve purchases, generate images, alter audio masters, expose Agent memory or publish other content roles.
- No database migration is needed: the existing admin_audit_log stores immutable change receipts, while media_links preserves candidate and previous assets.

## Security and stale-state controls

- Admin authentication and CSRF are inherited from admin/api/media.php.
- Apply verifies the exact approved generation job, attached Media Library image, allowed role, existing content target, and separate explicit publish confirmation.
- Apply and Restore pass expected_current_link_id; the server rejects an outdated preview.
- Restore requires a target/role-matched audited revision whose new link is **still** the current public one, and whose previous managed link still exists.
- Existing Media Library media-exposure and generated-image approval guards are reused before publication.
- Admin Agent Brain receives a non-LLM receipt; no private image bytes, chat transcript, provider key or prompt is forwarded.

## Certification gates

- PHP syntax lint and all existing regressions must pass in GitHub Actions.
- New 91B20G Node checks cover role/approval guards, immutable receipts, stale state, reviewed UI, responsive selectors and release integration.
- Production QA still needs a real Site/Story/Campaign/Release/Song preview, public visibility, touch/mouse interaction and restore during concurrent edits. Static tests cannot certify those live behaviors.
