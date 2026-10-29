# Section 91B4 — Voice output coordination and Admin music (v1.3.169)

## Scope

Public and Admin Agent speech use a shared output state (`preparing`, `speaking`, `cooldown`, `idle`). Normal voice transcription is suppressed from speech preparation until 1.1 seconds after final output or a confirmed interruption. The independently transcribed and acoustically verified human barge-in path stays available during a reply; pause-probe echo decay is extended to 320 ms with a 930 ms confirmation deadline on live public/Admin surfaces. The guard rejects stale speech-generation completions, and neither browser nor ElevenLabs playback is treated as complete until its own end condition.

The established public catalog player still executes Agent play, pause, resume, previous/next, status and volume actions; music continues while talking and ducks beneath voice. The Admin Agent now has a local browser-owned player for authorized same-site catalog previews, with visible transport, volume, voice/text controls and automatic music ducking. The user can say `Play Earth Girl`, `Pause music`, `Next song`, `What is playing?`, `Volume to 35 percent`, `Mute music`, `Unmute music`. These local controls do not call server mutation endpoints, access private masters, or load third-party URLs. Local controls are labeled in Admin popup conversation; they are not misrepresented as durable Agent Brain history.

## Certification and limitations

- `node tests/section91b4-voice-music.cjs` covers guarded speech lifecycle, stale generation, cooldown, safe Admin preview playback, exact commands, player volume, speech ducking, acoustic probe muting, autoplay denials and public/Admin integration.
- Previous `section91a`, `section90q`, `section90r`, `section90m` and all CI release-gate voice/music tests remain required.
- Browser autoplay may require the first Play to be clicked by a person; text/voice controls report a denied play rather than claiming success.
- A 1.1-second cooldown avoids most post-TTS echo, but room acoustics, external speakers and browser speech recognition vary. These are code-level checks, **not** proof of real-room microphone accuracy.

## Live acceptance

1. Enable public conversation. Let 10 long Agent replies finish with laptop speakers active. No self-replies or restart loops.
2. Interrupt a long public response with `Stop` and a new question. Confirm old speech stops and only the human phrase is submitted.
3. Repeat 1 and 2 in Admin using a local browser voice. Confirm replies complete and hands-free listening resumes.
4. Ask public Agent `Play Earth Girl`; then `Volume to 35 percent`, `Next song`, `Pause music`, `Resume music`; verify actual audio changes and spoken state.
5. Repeat Admin music commands, confirm compact now-playing bar, audio from same-site previews only, music duck/restoration and probe silence.
6. Keep music playing during conversation. Agent speech must not be treated as a user turn; say a real interruption, then ask a follow-up without tapping the mic.

No migration is required. Deploy the full exact-commit archive and preserve private server config and data.
