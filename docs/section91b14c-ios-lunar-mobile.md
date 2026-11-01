# 91B14C · iPhone voice session + Moonlit Horizon canvas

Version: 1.3.203

## iPhone Chrome / Safari voice

The original browser Web Speech recognizer previously stopped on **every**
accepted utterance, and JavaScript scheduled a fresh `start()` after each
Agent answer. On iOS WebKit, restarting recognition may display a fresh
microphone/speech permission dialog. This can look like the Agent is getting
stuck between *Thinking* and *Listening* even when the site's microphone
permission is already allowed.

The iOS path now:

1. Requests `continuous=true` when the user directly taps Start Chat or the
   existing microphone control. No extra `getUserMedia()` session is created.
2. Keeps the same recognizer running while an LLM request or speech reply is
   in progress, without calling `stop()` or `abort()` per conversation turn.
3. Drops interim/final results while the Agent is busy, speaking or in the
   post-speech echo cooldown. It tracks final result indices so the browser's
   cumulative result list cannot replay older utterances as new commands.
4. If WebKit ends the recognizer (or returns a recoverable capture error), it
   **never automatically restarts** on a background timer. The orb shows
   **Resume Chat** and the normal microphone button resumes on the next real
   tap. Actual permission/service denial still stops the conversation with
   an explanatory error.
5. Desktop still uses the existing one-turn recognizer and guarded restart.
   The optional ElevenLabs WebRTC Speech Engine retains its own session path.

The Web Speech API still owns the microphone and its vendor-specific session
lifetime. `continuous=true` is advisory; some iOS versions may end the
recognizer after a short interval. The safe result is a visible **Resume Chat**
button rather than repeated surprise permission prompts. Truly unlimited
hands-free capture requires a separate, continuous stream and authorized
speech-to-text backend; this update does not pretend to provide that.

## Canvas & mobile UI

- `Moonlit Horizon` is an allowlisted fourth atmosphere, backed by the user's
  supplied night-moon landscape optimized to `agent-atmosphere-lunar.webp`.
  Its particle orb, header, menus, chat bar, native page pane and player adopt
  a silver/moonlight/slate color system. The photo remains unobstructed by mesh.
- The vertical `+` navigation rail gains a barely visible tinted, blurred
  background (not a modal) for image contrast.
- The player reveal pill beneath the orb becomes **Start Chat**. It directly
  starts listening, disappears while active, and reappears as **Resume Chat**
  when an iOS recognizer stops. The full music player remains accessible from
  a small orbital music icon when a track is loaded.
- On small screens, the existing Activity bell opens a narrow right-side
  slide-out. All notification types, tabs, action/chat buttons and backdrops
  remain wired to the existing notification renderer and APIs.
- The mobile chat composer input has a 16px font-size to avoid iOS's automatic
  focus zoom. Pinch zoom remains available.
- The Record Builder stays its separate `?view=builder` page.

## Playback completion & byte-range streaming

- The public Media Library endpoint previously treated the suffix request
  `Range: bytes=-100` as *the first* 101 bytes instead of the final 100 bytes.
  A standalone range parser now produces byte-accurate `200`/`206` responses,
  rejects unsatisfiable/multipart ranges with `416`, honors HEAD, and includes
  `no-transform` so network intermediaries should not recompress byte slices.
- When an ordinary published full song reaches `ended` much earlier than its
  catalog duration, the player now **stops and reports the discrepancy**
  instead of logging a complete listen and auto-advancing or replaying it.
  Deliberately published preview-only/split-preview tracks are exempt.
- This guards against incomplete media files or bad streaming. It cannot
  reconstruct missing audio: if a file genuinely contains only part of a
  recording, replace that asset with a verified full-length upload.

## QA

- Browser JavaScript parses; all 83 local CJS suites pass.
- Section 91B14C adds behavioral coverage for cumulative iOS results,
  deferred Agent echo, no hidden restart timers, theme allowlist, binary WebP,
  Start/Resume Chat, notification slide-out and keyboard zoom prevention.
- Browser/device acceptance still required on actual Chrome iOS and Safari:
  grant once, speak a question, observe Thinking and reply, then continue
  speaking without an automatic new permission prompt. If WebKit ends the
  recognizer, verify Resume Chat appears and that a deliberate tap recovers.
  Test music playback, vocal echo and lyric interpretation concurrently.
- Section 91B14D adds PHP byte-range vectors for regular, open-ended and
  suffix ranges, plus behavioral JavaScript tests for early-ended audio.
  On iPhone, play several full-length tracks and seek near the final 30
  seconds; confirm there is no restart at 60–90% or spurious queue advance.
