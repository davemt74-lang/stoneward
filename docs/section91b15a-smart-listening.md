# Stonefellow 91B15A — Smart Listening and Temporary Playlists

Build: **1.3.204**

## Command design

The existing conversational LLM determines intent and the PHP policy turns
that intent into an allowlisted browser action. Typed and recognized voice
requests converge on the same browser-owned audio player skill and result
receipt. The LLM is never given permission to supply its own media URL.

| Spoken or typed request | Route / browser action | Result |
| --- | --- | --- |
| “Create a random playlist” | `playlist_random` / `load_temporary_playlist` | Randomized, playable published songs; starts track one; opens the queue drawer |
| “Show the queue” | `queue_open` / `open_queue` | Reveals active temporary playlist plus any separate saved Up Next items |
| Orb playlist icon | `show-queue` | Opens the same queue without changing playback |
| “Load albums” | `page_navigate` / `open_view(releases)` | Displays Releases; Builder remains a separate page |
| “Stop music” | `player_stop` / `stop_player` | Pauses and seeks to zero; does not destroy the temporary playlist |
| “Pause music” | `player_pause` / `pause_player` | Pauses without seeking |

## Temporary session contract

- The random mix exists only in browser playback state. It is not written to
  `user_playlists`, nor automatically saved in the account.
- Song IDs come from `sf_agent_public_catalog()`, are shuffled, and must have
  playable audio. Client-side validation re-checks the published player list,
  rejects duplicate/unknown IDs, and prevents arbitrary URLs.
- The existing right-side queue drawer gains a clearly labeled **Temporary
  Playlist · Not Saved** section, numbered tracks, current-song highlight,
  song-specific Play buttons, and a separate saved Up Next list when present.
- The Playlist button appears among the curved orb tools whenever the
  temporary playlist exists and reopens the queue after dismissal. This
  temporary UI can be used by guests, without a persistent queue API call.
- Next, Previous and natural track completion advance the same indexed
  playlist; after its last song, the playlist remains available to replay
  until replaced or the player is closed.
- Saving the mix can be added later as a separate explicit action; it must
  never silently create a persistent playlist.

## Voice and music ownership

- Recognition stays armed during music, but the player is ducked only for
  recognized wake-word input or actual Agent speech. Simply opening the
  microphone must not lower the track volume.
- A wake-word turn opens the intended 18-second follow-up window while music
  continues. Outside that window, wake-word recognition is required over
  music to avoid treating ordinary song lyrics as new requests.
- Agent speech guards, the iPhone mute interval, and an additional short
  echo check prevent its own last response from becoming another turn.
- Track playback and queue state remain the only authoritative sources of
  action success. Browser autoplay, microphone permissions and uploaded
  audio integrity remain device/server-dependent and require real testing.

## Verification

- `node tests/section91b15a-smart-playlist.cjs`
- `php tests/section91b15a-smart-playlist.php`
- `node tests/section90d-ambient-agent-voice.cjs`
- Full JavaScript test sweep and release-gate workflow

On-device follow-up tests: iPhone Chrome/Safari microphone permission once;
say “Stonefellow, create a random playlist”; verify queue and song transitions;
close and reopen with orb icon; speak a follow-up while the Agent is listening;
confirm music does not repeatedly duck in response to its own audio.
