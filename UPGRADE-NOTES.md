# Stonefellow v1.3.35 Upgrade Notes

1. Upload/extract the v1.3.35 deploy over the existing installation.
2. Preserve production-owned data, storage, database configuration, secrets, store configuration and generated catalog/config JS.
3. Run /upgrade.php.
4. Database target advances from 1.3.29 to 1.3.30.
5. Migration 2026-10-11-031 creates video_features and video_events.
6. Migration 031 refreshes Artist Homepage defaults so Featured Video appears on existing installs.
7. Create Video Hub records from Admin → Media Library → Public Video Hub.
8. Save Draft first, then attach managed Media Library assets.
9. Public playback requires one Public MP4/WebM video with known duration and one Public poster at least 640×360 in either orientation.
10. MOV can remain a private/source asset but does not satisfy public playback readiness.
11. Optional captions must be managed WebVTT with a valid WEBVTT header.
12. Publish only after readiness is green.

Application: 1.3.35
Database schema target: 1.3.30
