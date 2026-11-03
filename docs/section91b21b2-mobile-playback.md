# 91B21B2 — Mobile playback recovery and media streaming

Release: v1.3.238. Base: v1.3.237 / 6ca4e0434be6a40d342dc4123d18a35cb6e59227.

## Reproduced defects
- The previous recovery function restarted at 0 after an error at 60/240 seconds when reload metadata had NaN/Infinity duration, or currentTime assignment threw. The old handler played even when restoring the offset failed.
- The original media endpoint held its PHP session lock for the entire audio response; a second request with the same session cookie waited behind an unfinished 64MiB fixture response.

## Changes
- Save the last verified playback position, including intentional backward seeks. MediaError resetting currentTime cannot discard that checkpoint.
- Reload once per playback epoch, wait for a verified seek, and then play. Metadata/progress/durationchange/seeked can retry an unavailable seek. Never fall back to playing at zero. Timeout remains paused and preserves the checkpoint for manual retry.
- Explicit pause, stop, close and new-track actions invalidate obsolete recovery. Old promises/listeners are cleaned up. Decode/source errors are not retried automatically.
- An early native end cannot be called complete merely because duration metadata still describes the full song. Intentional short previews remain supported.
- Complete existing authorization checks, then release the PHP session before streaming bytes. Existing single/suffix/open-ended byte ranges, private-master boundaries and download permissions remain intact.
- Member-only and Admin media are private/no-store. Only an intentionally unrestricted public link permits public caching.

## Acceptance and limitations
- 19 Node runtime/integration checks, executing the actual recovery functions and lifecycle with controlled media states.
- 17 HTTP checks executing the production route in a disposable fixture root with synthetic auth/metadata: exact ranges, concurrent same-session streams, restricted media and cache policies.
- 7 real Chromium checks at 375px: decode a generated four-minute MP3, inject a network-error signal at one minute, reload/seek and play to the real end at accelerated playback speed; assert one completion, no replay and explicit-pause cancellation. MP3 bytes are loaded as a Blob; real HTTP delivery is certified separately. This is not a physical iPhone Safari test or proof of the user's exact hosting failure.
- Existing full Release Gate remains enabled; targeted playback workflow is permanent.

## Deploy
Upload and extract the single full-source ZIP; preserve configuration, database and media. No new migration. Refresh/reopen the mobile page to load matching v1.3.238 scripts and service worker. No paid providers, customer data changes, or republishing audio.

## Hands-on acceptance still needed
Play the affected song on the user's phone past the previous restart point, then to its end; test pause/resume and seeking. If a genuine source/host interruption cannot be recovered, the new behavior reports a paused saved position instead of replaying the beginning.
