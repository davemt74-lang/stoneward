# Section 91B18A · Stonefellow Song Moments · v1.3.223

## Outcome

One published song has a timestamped timeline shared by the public player, the Agent Canvas orb, and the administrator. As playback reaches a timestamp, Stonefellow presents the artist note, public photo, linked Story, or approved fan comment from that moment without starting a new chat or recording the microphone.

## Fan-facing behavior

- **Moments ✧** in the existing player or the orbital navigation opens the right-side Song Timeline drawer. Markers appear along the existing progress control. No second audio player is created.
- Each live orb card shows its time and content; Story cards navigate into the existing published Stories module. When several moments share one playback interval, a **Next moment** button keeps the remaining cards reachable.
- Optional spoken artist narration is **off by default** and is suppressed during recording, transcription, and active listening turns.
- Signed-in fans can submit a timecoded comment, which is **pending** until administrator approval. A fan can see the status of their own submissions, including rejected submissions. Guests can only keep a private draft on this device, then return to submit it after signing in.
- Public images must already be linked as public in the existing Media Library. Published Story references are checked again when the public timeline is read.

## Admin behavior

- The main admin sidebar has **Song Moments & Timeline** under Music and **Comment Moderation** under Fans & CRM.
- Timeline editor: select a published song and timestamp; add or edit artist notes, select existing public Media Library photos, or link an already-published Story. Drafts are hidden from fans.
- Moderation: review pending comments with the song and timestamp; explicitly approve, reject, or remove. Approved comments become public; removed comments disappear. An append-only moderation decision row records the administrator and state transition. Existing admin audit logging also receives the decision.
- The Admin Dashboard shows **Awaiting Approval** and **Live Timeline** metrics, with each tile opening its appropriate sidebar destination.

## Data and privacy boundaries

- SQLite/MySQL installations create `song_timeline_moments` and `song_timeline_moderation` on first use. These are additive tables; existing Stories, Media and catalog records are reused.
- Public `GET api/song-moments.php?track_id=...` returns published entries plus at most the **current authenticated fan's own** comments. POST comment requires current-user authentication and CSRF; submission identity is taken from the authenticated server session.
- Admin `GET/POST admin/api/song-moments.php` enforces admin authentication, CSRF for all writes and explicit confirmation for publishing/moderation.
- Public fan comments never bypass the queue. Rate limits: 12 new comments per 24 hours and at most 8 outstanding pending per user.
- The implementation does not expose unpublished song data, private media paths, draft Stories, private fan comments, or browser microphone recordings.

## Verification

- `node --test tests/section91b18a-song-moments.cjs`: 6/6 targeted checks.
- `node --test tests/*.cjs`: 161/161 regression checks.
- All modified JavaScript files pass `node --check`.
- PHP CLI is unavailable in the build environment, so the PHP endpoints and MySQL/SQLite schema must also be exercised on the staging HostGator deployment before production acceptance.

## Manual staging smoke checks

1. Play a published song; add an artist note at 0:08 and a linked published Story at 0:15. Verify both appear on the player and in the orb as the song plays.
2. Add a publicly linked photo in Media Library, then attach it to a song timestamp and verify no private/original storage path appears in the public response.
3. Submit a signed-in fan comment at 0:10. Verify it is absent from another listener's timeline and is shown as **Pending** to the author.
4. In Comment Moderation, approve that comment. Verify it appears in public and can be removed again. Reject a second comment and confirm it remains non-public.
5. Open the Admin Dashboard. The pending count should change when the comment is approved; its tile opens Comment Moderation.
6. Check desktop/mobile drawer layering, orb card placement, Story navigation, and that the normal Chat Footer still begins closed on mobile.
