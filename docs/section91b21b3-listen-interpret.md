# Listen & Interpret sidebar repair — v1.3.239

Urgent playback follow-up to v1.3.238. Does not change the playback recovery implementation or schema.

## Changes
- Recognition sessions render inside the existing native page rail, with a 460px desktop sidebar and full-width mobile rail. No duplicate floating Record Song panel.
- Minimizing/closing the rail never stops recognition or deletes its draft. Transcript and completion updates cannot reopen a dismissed rail or replace another page.
- Persistent Show lyrics / Stop controls and truthful TRANSCRIBING LYRICS / STOPPING LYRIC TRANSCRIPTION status. Ordinary player/chat state updates cannot overwrite active recognition status.
- The Listen icon remains in a stable ring slot. Record Studio is disabled while recognition is active; a separate explicit recording session retains its own Stop slot.
- Production typed commands accept both “Listen and Interpret” and “Listen & Interpret”. Advisory model capture routing is corrected when the explicit intent is live listening.
- Unsupported listening opens an explicit unavailable message. No MediaRecorder, microphone or paid transcription fallback is introduced. Stop returns an editable unverified draft; interpretation remains a separate explicit request.
- Starting a new recording/listening request is rejected during pending transcription or analysis. Repeated Listen opens the existing session rather than starting a duplicate.

## Verification
10 Node checks execute production command/action functions and verify routing boundaries.
42 Chromium UI assertions at 375px and 1440px use the real canvas, transcript renderer and recognition lifecycle with synthetic recognition results. No live browser speech backend is contacted.
Full historical Release Gate and targeted certification must pass before merge. Screenshots retained by CI.

## Important limitation
Native iPhone/iPad/Android player-track recognition remains unsupported by the existing conservative capability guard. This is a control/UI/routing repair, not a new mobile speech-to-text service. A mobile device receives a clear unavailable message, never a fake running status or an unrequested recording. Mobile-width UI acceptance is not a physical iPhone speech recognition test.

## Deployment
Upload/extract the single deployment ZIP over the existing application, preserving configuration and media. No new migration. Refresh the mobile page to load the versioned assets.

## Remaining
Implement a separately reviewed mobile-compatible transcription path (for example bounded server-side processing of authorized catalog audio) with explicit provider usage, cost controls and real-device acceptance. Existing authentication, billing, session, privacy and audit backlog is not marked complete by this release.
