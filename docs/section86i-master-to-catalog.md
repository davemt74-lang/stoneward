# Stonefellow 86I — Production-to-Catalog Private Master (v1.3.88)

**Existing integration, not a new music catalog or checkout.** This workflow uses Stonefellow's existing `media_assets`, `media_links`, `sf_track_media_publish_master`, `private_master_audio`, fan-owned download and vinyl-licensing contracts.

## Workflow
1. Edit/recall the REAPER mix in Stem Studio. Render 48 kHz, stereo 16-bit PCM WAV and save its browser-measured 86G evidence. This now includes the exact WAV SHA-256 computed with browser WebCrypto.
2. Apply 86H checks and type **REVIEWED**; choose Streaming, Owned Download or Archive. Vinyl candidate needs cutting-engineer verification outside this browser and cannot use the automated private-master promotion.
3. Click **Stage this rendered WAV privately**. The server accepts only an actual uploaded file with an exact compatible PCM header, valid size, current reviewed audio signature, and SHA-256 identical to the analyzed WAV. The file enters the **existing Media Library** with the private `audio_candidate` role, with `public_visible=0` and `download_allowed=0`.
4. Independently check the staged media/track and type **PUBLISH PRIVATE MASTER**. The server rechecks the reviewed hash and project revision, writes a durable intent, and invokes the existing `sf_track_media_publish_master` promotion. This changes **only** the catalog's protected `private_master_audio` and leaves the existing public `audio` preview untouched.
5. A partial failure remains `publishing_in_progress`, and the API never automatically retries. A manual **RECONCILE MASTER** only finalizes the state if the exact expected private catalog reference already exists. Otherwise inspect the Media Library.
6. Admin Agent Brain can inspect aggregate readiness and private publication state. No full WAV, RPP, private audio path or fan secrets enter the LLM prompt.

## Limits and safety
- A browser-reported SHA-256 binds *a staged file* to *the browser's saved evidence*. It **does not prove** that the browser used correct master processing or prove true-peak/independent loudness certification.
- Existing purchases/ownership entitlements, vinyl print-license balances, public streaming preview and pricing are not modified. Public preview publishing remains a separate, explicitly reviewed Media Library operation.
- New WAV masters can be large (about 92 MB for eight minutes). HostGator PHP limits may need adjustment; staging rejects files over 100 MiB.
- Staging after a page refresh requires re-rendering the same mix in the same browser; for new 86I evidence re-run Render and Save Analysis and repeat 86H review. Existing 86G reports lack a cryptographic render SHA and are insufficient for publication.
- Strict staging prevents second concurrent staged/published candidates. Switching originals after publication continues through the existing governed Media Library workflow; no covert overwrite of media assets.
- If a file is uploaded but an interrupted save occurs, the candidate may remain as a private unassigned Media Library attachment. This is deliberate fail-closed behavior; inspect it before cleanup.

## Certification
- Full SQLite integration with the original Media Library role, protected ownership checks, preserved public preview, duplicate refusal and manual crash reconciliation.
- Private upload endpoint and metadata/format/hash rejection, strict revision and browser-reported evidence provenance.
- 86H/86G/86F and legacy 85B media publishing regression; full release gate and main deployment package/checksum.
- Field acceptance: render actual Stonefellow REAPER song, upload its WAV, independently listen/compare, then choose whether to promote as purchased original.
