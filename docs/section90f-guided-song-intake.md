# Section 90F — Guided Admin Agent Song Intake

Release: Stonefellow v1.3.138. No database schema or historical migration changed.

## Admin flow

1. In Admin Agent Chat, ask **“Stonefellow, let's upload a new song.”** Choose **Guided song upload**. The empty-chat welcome also offers **Upload a new song**.
2. Select a local WAV or MP3. Existing authenticated `media-upload.php` stores the recording and reports technical analysis and embedded tags; only a user-initiated file-picker click opens the browser picker.
3. Review detected title, release/album, genre, year, writers and private notes. Save a private draft with an explicit click.
4. Reload Admin Chat and expand **Private song drafts**. Choose **Edit draft** to reopen it, modify metadata and save with exact revision protection.

## Scope and boundaries

- Draft metadata is stored as owner-specific files under `storage/agent-song-drafts/<admin_id>/`, blocked from web access by `.htaccess`. Files are permission `0600`; directories `0700`.
- Reading or writing drafts requires an authenticated Admin account; posting requires CSRF. The server resolves the uploaded audio asset and checks `created_by`, category and exact owner. The Admin Agent cannot silently choose files.
- Duplicate submissions are idempotent by a 128-bit request key. The same audio asset cannot create duplicate draft records for that Admin. Edits require the previous payload hash and preserve audio identity.
- Draft creation and real updates are logged in Admin action history.
- No catalog update, release publishing, public URL, streaming entitlement, CRM send or external LLM call is performed by this workflow. These require a separate governed release step.
- Only summary metadata appears in the Admin draft list. Private drafts are not included in the public catalog, fan chat or AI provider context.

## Acceptance and rollback

- Client: `node tests/section90f-agent-song-upload.cjs`
- PHP: `php tests/section90f-song-intake.php`
- Syntax: `php -l api/agent-song-intake.php` and `php -l admin/api/agent-song-intake.php`
- Regression: Section 90B Admin module navigation, Section 90E Agent voice/scroll and full Release Gate.
- Host acceptance: upload a WAV and MP3 in Admin Chat; verify title/genre prefill, validate empty title/invalid year, create one draft, edit after reload, verify repeated save and duplicate rejection, verify no new public catalog entry and no audio playback link in fan catalog.
- No database migration necessary. Rolling back the PHP and JS files leaves private media and draft files on disk; never delete them during rollback.

## Deferred

Section 90G will need an explicit review of mapping a private draft to the published catalog, artwork/rights controls and publication actions. 90F deliberately does not publish.
