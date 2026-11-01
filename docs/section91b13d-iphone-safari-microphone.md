# 91B13D — iPhone Safari microphone startup and speech-service diagnosis

Build: **1.3.198**

## Report

On iPhone Safari, clicking the Stonefellow microphone may show a permission/access
denied error even after the website and Safari microphone permissions were allowed.

## Root causes addressed

1. The microphone click handler awaited a network call to determine whether the
   ElevenLabs Speech Engine or original Web Speech mode was selected. Waiting
   could lose the transient user activation needed when Safari starts speech
   capture.
2. The original voice mode simultaneously requested another live microphone
   stream for full-duplex barge-in detection, which can compete with iPhone
   WebKit's speech recognition capture.
3. The UI treated both `not-allowed` and `service-not-allowed` recognition
   results as ordinary microphone permission denial. Web Speech defines
   `service-not-allowed` as denial of the speech recognition *service*, which
   can happen independently of a permitted microphone. Safari's recognition
   service uses Siri; enabling Siri is an independent requirement.

## Changes

- The page renders the current server-verified effective engine selection into a
  small bootstrap value (without exposing credentials). Account Voice Controls
  also preloads it and caches it per authenticated user. Saving a new engine
  choice updates both values immediately.
- The microphone click uses the cached selection synchronously. Both native
  SpeechRecognition and the optional Speech Engine can request capture in the
  actual tap turn, without waiting for a preference network request.
- On iPhone/iPad WebKit, do not start the extra interruption microphone monitor
  while the native recognizer owns the microphone. Desktop full-duplex behavior
  is unchanged. On mobile, use the existing Stop speaking action to interrupt
  Agent audio if needed.
- Error messages separate unavailable speech recognition service, blocked
  speech input, and unavailable audio capture. They no longer assert that the
  user denied microphone access without evidence.
- Add behavioral Node regression tests to the release gate.

## iPhone acceptance

1. On a HTTPS Stonefellow installation, select the original voice engine in
   Account Settings (or the optional Speech Engine if configured), reload and
   wait for the initial settings request.
2. Tap the microphone. The request must start during the direct tap, without
   running the engine-selection network request.
3. Speak a short prompt. Verify the Agent shows the recognized words.
4. Repeat after allowing Safari's microphone permission. No parallel acoustic
   capture should compete with the recognizer on iOS.
5. If Safari returns `service-not-allowed`, the UI must explain that Apple's
   speech recognition service can be unavailable even with allowed microphone
   access and advise checking Siri in iPhone Settings.
6. If Safari returns `not-allowed`, show a non-accusatory, actionable message.
7. Confirm desktop microphone and full-duplex interruption still work.

## Platform limitation

Apple WebKit has documented iOS capture failures that surface as `NotAllowedError`
even after permission is granted (notably after some media-services resets).
This patch addresses avoidable application races and diagnosis; it cannot
override iOS speech service policy or a broken OS-level audio session.

Sources:
- https://webkit.org/blog/11648/new-webkit-features-in-safari-14-1/
- https://developer.mozilla.org/en-US/docs/Web/API/SpeechRecognitionErrorEvent/error
- https://bugs.webkit.org/show_bug.cgi?id=319706
