# Stonefellow 91B14B — Ember controls and live Agent orb

## Interaction

- The single `#voiceTranscript` node remains the authoritative recognition caption. While the public Agent Canvas is active, it is moved from the chat footer to the orb's `.sf-agent-live-transcript-slot`. It is restored in its original DOM position when the canvas unmounts. Non-canvas routes keep the existing footer transcript.
- A compact preview below the orb shows the latest typed or spoken Agent Chat message when live transcription is hidden. The arrow opens the existing Agent Chat popup; it does not create a second conversation or speak preview messages.
- The previous "Drag to move · Click to expand" instruction and the footer-level listening transcript row are absent on the Agent Canvas. Dragging the orb and clicking to expand still work. Clicking the transcript feed is not treated as a drag.
- Listen & Interpret and Record Clip now occupy small SVG icon buttons along an outside arc above the orb. A third Stop Listening icon replaces them as needed. Titles, aria-labels, focus states, and tooltips expose their meaning without taking space on the canvas.
- `positionOrbitTools()` lays out every visible orbital icon using a count-based spread and visual-size radius; future actions can join this group without a hard-coded two-button row.

## Appearance

- Ember uses ivory/yellow tokens throughout the public footer, chat controls, notification badge, player toggle, seek bar, player copy, in-orb caption, and side panels.
- Mesh and Violet keep their independent palette values. The dedicated builder route (`?view=builder`) and native left page rail behavior are unchanged.
- Mobile icons shrink modestly, captions clamp to two lines, and the orbit radius scales to the visible orb.

## Acceptance

1. With Ember selected, play a song. The player pause button, progress accent, notification badge, microphone, send button, and live captions should be warm gold/ivory, with no blue highlights.
2. Start Agent listening and speak. Partial/final transcription appears directly under the orb, not above the sticky chat bar.
3. Submit a chat turn. The latest message appears in the compact orb preview when transcription is hidden. The arrow opens the full existing chat.
4. Start Listen & Interpret; its visible icon transitions into Stop Listening. Record Clip stays separate from lyric listening.
5. Switch to the full builder. The voice transcript returns to the regular footer; no iframe or duplicate microphone session is created.
6. Repeat on mobile Safari; the orb remains draggable, the icons stay above the outside ring, and the chatbar remains usable.
