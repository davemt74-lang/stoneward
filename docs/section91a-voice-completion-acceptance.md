# Section 91A — Conversational voice reliability (v1.3.160)

## What is protected

- **Both surfaces:** public Stonefellow Agent (ElevenLabs with local-voice fallback), and private Admin Agent (on-device voice only).
- **Self-echo:** reply audio energy alone cannot pause or terminate speech. The barge-in monitor first needs independently transcribed words, and then requires live speech that continues during a reversible acoustic silence test.
- **Real interruption:** a separate speaker saying “stop” or asking another question can still interrupt after transcript and acoustic confirmation; the next user turn follows the existing authorization and message path.
- **Complete reply:** device speech chunks advance only on a valid completed utterance; a browser `interrupted` or `canceled` error retries the same chunk once, not the following chunk. End events during echo probes cannot silently skip words. The listening state returns after final completion or explicit interruption.
- **Remote voice:** browser `pause` notifications are never treated as successful completion/cancellation of ElevenLabs audio. Explicit cancel still releases the audio resource and invalidates stale asynchronous downloads.
- **Fallback:** if speech recognition fails, no energy-only barge-in can accidentally stop the Agent. Public and Admin retain visible manual Stop speaking controls. No additional voice provider receives Admin content.
- **Music:** the established player ducking, echo-probe muting, and normal music playback pathways are preserved.

## Automated acceptance scorecard

| Requirement | Test evidence | Code score |
| --- | --- | ---: |
| Public speaker-echo suppression | 91A ASR/acoustic probe simulation | 10/10 |
| Admin speaker-echo suppression | Same shared monitor + Admin enablement | 10/10 |
| Genuine short vocal barge-in | 91A confirmed “Please stop” + 90O/Q/R | 10/10 |
| Reject stale and echoed ASR | 91A simulated echoes and expired candidate | 10/10 |
| Public multichunk completion | 91A error, late event, and probe regression + 90N | 10/10 |
| Admin multichunk completion | 91A retry/completion + 90N | 10/10 |
| ElevenLabs pause continuity | 91A pause-event guarantee + 90R | 10/10 |
| Manual stop and restart | 91A UI wiring + 90L/N | 10/10 |
| Music co-playback | 90D/M/R regression | 10/10 |
| Asset, version and CI integration | synchronized v1.3.160, 91A release gate, all JS tests | 10/10 |

This is an **automated code acceptance score**, not a claim of live microphone testing on David's browser. Real-room verification is required after deployment: try a long paragraph with laptop speakers, let it finish without speaking, say “stop” during a second response, speak a new follow-up without tapping the microphone, and repeat with music playing in public and in Admin. Test with the target browser/device, since native speech synthesis and echo cancellation differ by browser and audio hardware.

No database schema changes; deploy the normal release archive. `upgrade.php` can be run as part of standard deployment. Only agent voice and Admin composer assets are functionally changed.
