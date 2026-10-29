# Section 91B5 — Unified Agent Audio Player Skill (v1.3.170)

## Delivery boundary

- Stonefellow public/customer/Admin only; this does **not** deploy a VP3 adapter.
- Registry: `api/agent-tool-registry.php` advertises the browser-only `audio_player_transport` with seven typed capabilities.
- Discovery: `api/agent-skill-registry.php` exposes one `unified_audio_player` skill in public, customer, and Admin contexts. Discovery never grants server execution authority.
- Shared contract: `assets/js/agent-audio-player-skill.js` validates structured actions, parses local natural-language commands for Admin, invokes the provided player adapter and reports its actual browser result. Unknown commands are refused. No credential or remote playback endpoint is accepted.
- Public agent: the authenticated `agent-chat.php` action goes to the shared browser dispatcher. Existing published-track lookup, queue, listening-session and legacy player handlers remain available.
- Admin agent: natural language/music commands go through the same shared dispatcher and the existing same-origin audio preview controller; former phrases remain supported.
- Both pages version-load the shared script; service worker pre-caches the exact new asset with v1.3.170 cache identity.

## Commands to try

- `Play Earth Girl` (where this song is published and its audio preview is available).
- `Pause music`; `Resume music`; `Next song`; `Previous track`.
- `What is playing?`; `Volume to 35 percent`; `Mute music`; `Unmute music`.
- Public music mode supports the existing `Stonefellow` wake phrase and an 18-second post-reply follow-up window.

## Safety and honesty

- Browser/player output is not a privileged server tool; registry audits must keep `sf_agent_tool_executable('audio_player_transport', ... )` false.
- Public Agent Chat remains login-gated; anonymous visitors may use the regular player, but do not gain private Agent access.
- Only published catalog tracks in the public player and same-origin catalog previews in Admin are playable.
- Browser autoplay may require a manual Play click. The player reports that failure; it never silently claims a started track.
- Shared dispatch only accepts seven action types and bounded numeric or track fields. Remote URLs and arbitrary payload fields cannot reach its player adapter.
- Volume is restored after agent speech; Section 91B4 echo prevention and verified human barge-in are unchanged.

## Certification and live acceptance

1. `node tests/section91b5-audio-player-skill.cjs` (shared parser/dispatcher, public/Admin wiring, local audio controls, autoplay, remote/private audio refusal).
2. `php tests/section91b5-audio-player-skill.php` (registry, skill discovery, authorized roles, no server execution).
3. Run all Section 89–91 voice suites and the complete GitHub Release Gate before merging.
4. Live public signed-in: ask to play a published song, set volume, pause, query status; confirm the actual footer player state.
5. Live Admin: request playback, trigger autoplay rejection, ask status; confirm no private master/remote audio is used.
6. Converse and interrupt during music: agent speech lowers music without transcribing itself, then restores the selected volume.

No `upgrade.php` schema change is introduced by Section 91B5.
