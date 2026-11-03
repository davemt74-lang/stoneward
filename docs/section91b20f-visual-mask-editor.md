# Stonefellow 91B20F — Visual Mask Editor

Version: **v1.3.232**. Continues Image Studio 91B20A–91B20E.

## Admin workflow

1. Open **Admin → AI Image Studio**, select an eligible private/approved reference from Media Library, then choose **Open Visual Mask Editor · brush / crop** beneath the edit-mask selector.
2. The authenticated reference image appears in a full-width responsive dialog. Paint the areas the AI should change. Cyan is a visualization of editable pixels only; the saved PNG is a conventional black-opaque/transparent mask.
3. Choose **Brush · edit**, **Restore · protect**, or **Rectangle · edit**. Undo, redo, and keyboard shortcuts work on brush and rectangle operations. The **Crop / focus** tool zooms into a dragged rectangle and never changes the output dimensions.
4. Review the area, confirm upload, then save one PNG to the existing **Admin-authenticated Media Library** uploader. The image retains the exact original source dimensions.
5. The uploaded PNG is selected as the optional mask for the current Image Studio draft. Mask-use consent is reset to false and must be re-acknowledged. The original reference permission, prompt review, paid generation approval, generated-image approval, and public publication all remain separate.

## Security and privacy

- Mask rasterization, undo/redo, crop/focus, and transparency preview are client-side only.
- The editor never invokes OpenAI, creates a prompt draft, starts image generation, or publishes media.
- On explicit upload, it sends one CSRF-protected, same-origin multipart form request to admin/api/media-upload.php; the original server route handles private managed storage and Admin audit logs.
- The image is retrieved through the existing authorized Media route; no remote image URL, absolute filesystem path, or provider key is accepted.
- The same source and mask bytes go through the previously built server hash, dimension, alpha and approval checks before any separately approved paid edit.
- A missing reference, inconsistent dimensions, >16 MP canvas, tiny/empty alpha selection, or oversize PNG fails locally without an API charge.
- Existing Media Library reference lists cap at 1,000 items, so a newly uploaded mask beyond that window may need a later paginated selector release. The upload is still retained privately.

## Interaction design

- Pointer events support touch, pen and mouse.
- Brush width is in source-image pixels (8–180 px), independent of viewport zoom.
- Undo/redo replay the vector stroke/rectangle history, avoiding a stack of full-resolution image snapshots.
- The crop/focus rectangle only adjusts the viewport transform; final PNG coordinates remain the source geometry.
- Escape and a close button discard with confirmation; Tab cycles the dialog focus controls; Ctrl/Cmd+Z, Shift+Z and Ctrl/Cmd+Y control history.
- One page-level dialog scroll handles small screens; no nested scrollable toolbars or canvas panels.

## Test plan / certification

- Node test: tests/section91b20f-visual-mask-editor.test.cjs
- Node syntax: admin/assets/image-mask-editor.js
- Existing 91B20A–91B20E tests and all Stonefellow release-gate checks.
- Browser interaction test simulates transparent painting, undo/redo, focus/crop, mask geometry, permission-separated private upload and no paid call.
- GitHub PR release gate and post-merge workflow must both pass before deployment ZIP is delivered.

## Release scoring

| Area | Target | Automated evidence |
| --- | --- | --- |
| Mask brush, restore, rectangle, undo/redo | 10/10 | Source and interactive simulation |
| Crop/focus without output resizing | 10/10 | Geometry and upload simulation |
| Admin-private upload and separate approvals | 10/10 | API/CSRF/static contract and UI simulation |
| Responsive and accessibility basics | 10/10 | CSS, keyboard and pointer event assertions |
| Production browser/provider certification | Pending live test | Requires deployed Admin, actual image upload, iPhone browser, and an authorized paid edit |

This release cannot claim that OpenAI honored a real mask or that every mobile browser layout has been visually approved until those live checks occur.

**Recommended follow-up:** paginated source/mask gallery (beyond the existing 1,000-image cap), brush refinement/softness, and live QA of provider edit fidelity.
