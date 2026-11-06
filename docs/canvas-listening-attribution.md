# Canonical listening across canvas experiences

This section connects parent-owned native media playback to the existing `listening_events` ledger. It supplies playback and reporting primitives for authored interfaces; it supplies no player widget, theme or scene template. Image, vision and production expansion remains deferred.

## Playback and attribution

The main player, compact footer and `StonefellowAudio.play(track)` use one native audio element and one observer. They share a play instance rather than reporting a second play when another control operates that element. A canvas initiating playback supplies a stored scene or approved gallery reference; the server resolves its identity and exact code hash. Standard application playback uses the app context. Attribution remains bound to the initiating instance as controls and songs change.

`await StonefellowAudio.createPlayer(track)` optionally creates an independent parent-owned native player. Its handle provides playback, seeking, volume, state subscriptions and disposal without controlling the main player or prescribing visible UI. The same observer reports its actual native media events. Closing/replacing the canvas, revoking its session or changing accounts disposes its independent players. Visually hiding the agent does not dispose playback.

`getAudio(track)` remains available for raw MP3 bytes, decoding and custom processing. Retrieval is not playback evidence. Audio running wholly inside authored code is unverified and does not generate claimed listening totals. Browser gesture restrictions remain native errors; denied playback must not produce a start.

## One ledger and replay protection

The versioned listening protocol obtains its session/CSRF credentials from the authenticated host endpoint. Each real playback has a random identity; every queued event has a stable identity retained across network retries. Identical replays return the recorded result, while conflicting reuse is rejected. Canonical event insertion, measurements, progress and replay receipts must commit atomically.

Existing start, resume and completion semantics remain in force. There is no new 30-second play qualification. A resume does not become another start. Historical and legacy callers remain supported with explicitly unknown evidence/attribution; they are not retroactively assigned a canvas or measured duration.

## Listening time and privacy

`listened_seconds` measures reported elapsed listening time, excluding pause, seeking and buffering. Playback speed changes media advancement rather than the meaning of an elapsed second. Native advancement and server elapsed time bound reported time. Delayed network delivery can conservatively reduce credited time; the metric is not proof that a person heard the audio. `measured_events` and `legacy_events` expose coverage so an absent historical measurement is not presented as historical silence.

Existing user activity and CRM listening events commit once with the canonical report. A durable outbox creates deferred automation intents after playback commits; each listening-derived step claims against the current account, privacy epoch and retained source before execution. Clearing history blocks work that has not been claimed. External work already dispatched cannot be recalled, and uncertain interrupted steps are not automatically replayed.

Overall totals, per-track rows and attribution groups come from the canonical ledger. Canvas engagement and technical performance remain separate metrics. Current-account canvas data exposes only that viewer's authorized aggregate fields through the existing capability/consent system.

Listening-history opt-out preserves anonymous overall totals while removing account and private-canvas linkage. Privacy changes rotate reporting credentials; stale reports cannot restore a cleared history or another account's records. Public gallery attribution may remain public. Exports, history clearing, account closure and existing retention controls must account for play state and receipt records as well as canonical rows.

## Acceptance and release status

Independent review found no remaining material blocker: **10/10 against the scoped automated acceptance criteria for supported host-observed main and independent playback**. Overall integration and GitHub release CI still gate merging. This score does not cover raw Blob/DSP measurement, every hosting environment, physical devices or the remaining outline.

- Core and actual authenticated HTTP tests: event validation, canonical totals, immutable attribution, replay/conflict handling, privacy lifecycle and evidence coverage.
- Native MariaDB tests: concurrent starts/completions, transactional rollback and privacy/account serialization.
- Client state tests: native timing, seeking, buffering, rates, event retry and account/session rollover.
- Native browser tests: main/footer/synchronized playback, independent playback, raw retrieval without a play claim, disposal, native gesture denial and mobile/desktop controls.
- Existing canonical playback, data permissions, project/gallery and release regression suites.

Local/native evidence: 37 core checks, eight additional aggregate/consent checks, 55 authenticated HTTP checks, 13 native MariaDB checks, 22 telemetry cases, 41 SDK lifecycle checks, 19 native app/PHP checks and 34 native independent-player checks. The complete v1.3 release regression also passes. Native WebKit coverage is being added to the CI browser matrix; physical-device coverage remains separate.

CI runs these in `canvas-listening-attribution.yml`. Tests use disposable data and synthetic audio without paid model/image calls or deployment database access. Live HostGator and physical-device validation remain separate release evidence.
