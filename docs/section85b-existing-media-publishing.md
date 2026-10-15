# Section 85B — Integration with existing Stonefellow Media Library

No second uploader, catalog, audio player, ownership system or payment logic.
The existing Admin song editor already supports media attachments with roles
`audio_candidate`, `preview_audio`, `master_audio`, and `download_audio`.

## Publishing contract
- **Public preview**: use the existing `publish_track_audio` action. It writes
  `track.audio`, which powers the website's existing player and catalog, and
  marks the asset public. It is never a paid-download original.
- **Private original**: explicitly confirm `publish_track_master`. It validates
  the preexisting private media asset, SHA-256 and path, then writes
  `track.private_master_audio`. Only the session-authorized owned download and
  scoped existing audio bridge can stream it; `api/media.php` must deny public
  visitors even if an incorrect duplicate public link exists.
- **Existing media linking policy**: a media asset already assigned as a
  `master_audio` cannot be made publicly visible, downloadable, or re-roled via
  a generic action. Conversely, a publicly linked asset cannot become a master.
- **Customer My Library**: the existing 85A wallet shows download readiness only
  if an explicitly assigned private master exists, not just a legacy catalog
  preview.
- **Record builder and vinyl publishing**: prefer the private original; when a
  song enters split mode, publishing must fail closed rather than manufacturing
  a short preview. Legacy catalog tracks retain legacy behavior until migrated.

## Migration / admin workflow
1. Open existing Admin Catalog → song → Media attachments.
2. Upload or select a short preview and publish it as Public Preview.
3. Upload or select a *different* full-quality WAV/MP3; keep it private and
   Publish Private Original. A private asset cannot also be public.
4. Verify preview playback, My Library ownership/download readiness and
   per-song vinyl-print rights.
5. Gradually migrate previously published songs; protected publishing begins
   only once separate originals are linked.

No stored media is bundled with source ZIPs. HostGator media files and catalog
entries must be preserved during deployment. No live Stripe or manufacturing
action is triggered.

## Acceptance
Targeted SQLite media-link/publishing regression, first-party 85A ownership
and legacy 84B7 streaming tests, source schema/compile tests, full release gate,
real MySQL certification, and final merged-main artifact verification.
