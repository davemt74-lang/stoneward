# 91B21B4 — mobile song audio to lyric text

Release: **Stonefellow 1.3.240**. This extends the 1.3.239 Listen & Interpret slideout and persistent orb controls with an OpenAI transcription path for browsers that cannot recognize the player's audio track directly.

## Listener flow

1. Sign in and play a published song.
2. Select **Listen & Interpret**. A read-only readiness request checks the enabled provider and the currently published listening copy. The selected interval begins after this check succeeds.
3. Let the desired section play, then select **Stop listening**. The server makes a temporary excerpt of that interval and submits one audio-to-text request to OpenAI. Pausing, reaching the end of the song, or reaching the 90-second limit also finishes the selection.
4. The lyric workspace stays in the native slideout. When minimized, the orb retains **Transcribing lyrics** and a Stop control. During the provider request, **Stop waiting** discards the eventual browser result; an already submitted provider request can still complete and incur usage.
5. Review the unverified text, use the pencil to correct it if needed, and choose **Review & interpret lyrics**. Interpretation uses the configured conversation model and existing approved songwriter context. It is a separate request after review.

Seeking, changing songs, navigation, or a player error cancels an unsubmitted selection. A stale response cannot replace a newer session. A double tap does not issue a second transcription request. The browser requires at least one second of selection, up to 90 seconds.

The separate **Record Clip / Record Song** control keeps its existing behavior. Listen & Interpret does not activate it, use the microphone, or make a saved recording. Supported desktop player-track recognition remains available through its existing route.

## Configuration and deployment

- In **Admin → AI Providers**, configure and enable **OpenAI / ChatGPT API** with the site's server-side API key. Keep the existing conversational Model ID: this feature independently uses `gpt-transcribe` for audio transcription.
- The host needs PHP cURL, the existing database drivers and encrypted provider configuration, and a writable PHP temporary directory. Production extraction does not require FFmpeg or permission to run shell programs.
- Publish a managed MP3 or PCM WAV listening copy through the Media Library. Arbitrary external URLs, unmanaged filesystem paths, manufacturing masters, and stems are not eligible.
- Source files are capped at **64 MiB** and the temporary upload at **20 MiB**. Large PCM files can use a published MP3 listening copy. Unsupported sources produce an availability message instead of switching to the microphone or Record Clip.
- Back up the installation and database, then upload and extract the direct deployment ZIP while preserving host configuration and media. Reopen the mobile page to load the 1.3.240 assets and service worker.
- No separate manual migration is required. On first submission, the application creates a `song_lyric_requests` metadata table using the existing database connection. It contains request identity, payload hash, processing state, token-reservation reference and timestamps, with account-deletion cascade. It contains neither audio nor lyric text.

## Audio processing

The new endpoint resolves the exact current public catalog reference to an authorized managed media asset. It validates path containment, file size, stored SHA-256 and playback-source identity. Readiness returns a fingerprint; submission must match it. Publication changes, private originals, expired selections and altered source bytes fail before provider upload.

PCM WAV extraction copies only the requested samples into a new minimal WAV container and strips ancillary metadata. Supported WAV is 8/16/24/32-bit PCM, mono or stereo, including PCM WAVEFORMATEXTENSIBLE with matching valid bits.

MP3 extraction parses complete MPEG-1/2/2.5 Layer III frames for CBR and VBR audio. It rounds the selected source bounds inward to whole frames, by less than 100 ms per edge. A synthetic silent frame initializes the bit reservoir when needed without emitting earlier song frames. Its separately reported silent duration is at most 72 ms; the returned source timestamps describe the selected song interval. Encoder delay and padding are read from recognized Xing/Info and LAME/Lavc/Lavf metadata. Metadata is not copied to the upload. CRC-protected/free-format MP3, other MPEG layers, VBRI and unsupported gapless timing are rejected explicitly. Reset decoder overlap can soften the first few milliseconds of a cut.

Temporary files use mode 0600 and are removed in `finally` before any JSON response is emitted, on both success and handled failures. The transcript remains in the current browser session until the listener explicitly saves a private note using the existing save action. As with any temporary-file workflow, a hard-killed host process can prevent in-process cleanup; system temporary-directory housekeeping remains the host's responsibility.

## Requests, usage and recovery

Authenticated GET readiness does not decrypt an API key, reserve tokens, extract audio or call a provider. POST requires the existing same-origin authentication and CSRF protections. The endpoint releases the PHP session lock before transcription so the request does not lock other requests from the same browser.

The server fixes the destination to `https://api.openai.com/v1/audio/transcriptions`, sends multipart audio with `model=gpt-transcribe`, verifies TLS, follows no redirects, limits the response size and makes no automatic provider retry. API keys stay server-side. The browser allows 75 seconds for the response; the provider transport has a 60-second timeout.

A per-account request identity and transactional claim prevent duplicate submissions and concurrent spending. A lost response is not replayed automatically. Existing Active Token enrollment and escrow rules apply, with a maximum of one in-flight claim per account for the bounded processing window and limits of eight submissions per five minutes and forty per hour. Successful transcription settles the existing token reservation; handled failures refund an unsettled reservation. Nonterminal claim metadata is retained so an interrupted worker does not lose its reservation reference.

## Verification

The release gate runs the new controller, extraction and SQLite runtime tests before constructing its deployment archive. A separate certification workflow runs the server runtime against both SQLite and MySQL, and executes the browser fixture in Chromium and WebKit at 375 px and 1440 px.

The audio regression independently decodes generated MP3 excerpts with FFmpeg/FFprobe and checks selected source time, before/inside/after frequencies, silent reservoir initialization, metadata removal, malformed input, bounds, WAV sample accuracy and extraction with all process-execution functions disabled. FFmpeg is a test dependency only.

Browser checks use production controller, action, UI and canvas code with simulated playback and provider responses. They cover the slideout, minimized orb status, Stop, review/edit/interpret gate, double taps, source changes, cancellation and late responses, and assert that neither microphone capture nor Record Clip is activated.

These tests do not measure recognition quality for a real song or certify a physical iPhone. No paid provider request or production account-setting change is part of automated validation. Final hands-on acceptance is: play the affected song on the iPhone, select a section, minimize the sidebar, stop from the orb, confirm the transcript matches that section, correct it if necessary and request interpretation.

## Provider contract references

Verified against the official OpenAI documentation on 2026-11-03:

- [File transcription guide](https://developers.openai.com/api/docs/guides/speech-to-text)
- [Create transcription API reference](https://developers.openai.com/api/reference/resources/audio/subresources/transcriptions/methods/create)

The existing Record Clip transcription endpoint is separate; this change does not migrate that endpoint's older model.
