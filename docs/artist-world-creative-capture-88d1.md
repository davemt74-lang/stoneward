# Stonefellow Artist World — Creative Capture 88D1 v1.3.111

## Intent
Establish the first-party **AI Director / Writer / Producer** input data pipeline. People with existing Stonefellow accounts submit real photos, audio, video, screen grabs, ambient moments, dialogue and ideas. The same system supports open-ended background story assets and role-targeted production requests.

## Role and privacy boundaries
All active registered users can contribute as Fans. Customer is an explicit non-privileged contributor role and must be assigned/verified; purchase entitlement is not automatically treated as marketing or team authority. Existing multi-role Band Member, Manager, Website Manager, Photographer, Road Crew, Sound Crew and other teams can be targeted for specific content requests. Audience roles never grant Team Workspace access or staff roster visibility.

## Flow
1. Admin Creative Director creates private draft or public-to-eligible-role shot request. AI-origin requests cannot bypass human approval.
2. Contributors discover eligible requests, capture or select a media file (up to 250 MB per upload), record description/dialogue/timestamp and attest submission rights, or submit a text-only story idea.
3. Existing Media Library privately ingests the original; the contributor receives a private receipt. Requests and submitted ideas are not part of the public Artist World or catalog.
4. Admin reviews. Status may become approved_for_edit, needs_release or rejected; **approved_for_edit does not mean publish or commercial clearance**.
5. Each request and submission creates a timestamped actor audit entry in Team Workspace. Private originals, EXIF metadata, sensitive people/likeness information and editorial context remain non-public by default.

## Planned AI production components
- **88D2 Media Intake Intelligence:** technical metadata, audio/video transcription with timecodes, still and scene descriptions, recurring objects/themes and provenance; photo/face identity remains a human, consent-based attribution, not inferred automatically.
- **88D3 Story Bible & Character Canon:** accepted story beats, narrative continuity, character/participant permissions, visual motifs and dialogue sourcing.
- **88D4 AI Director:** reviewable requested shots, suggested screen grabs, missing coverage detection, script drafts, shot selections and production prompts grounded in evidence.
- **88D5 AI Editing & Music Supervision:** build edit decision lists (EDL), assemble video using verified sources, use only entitled/licensed Stonefellow tracks and log every render source.
- **88D6 Production Review & Publishing:** human editorial approvals, rights/likeness releases, reviewable final timeline, exports and governed publication.

## Limitations
This release implements structured capture/shot requests/review, not automatic transcription, scene generation, AI editing, music licensing or final-video rendering. A production-grade large-file uploader will later need resumable chunks and explicit HostGator PHP size/runtime configuration. Live field testing of phone capture, media upload, account roles and PWA updates remains necessary.

No HomeServer modifications.
