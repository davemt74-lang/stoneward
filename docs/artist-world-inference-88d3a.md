# Stonefellow 88D3A — Governed inference queue

**Release:** 1.3.114 · **DB target:** 1.3.51 · additive migration `2026-10-18-042`.

88D3A provides a durable private queue for proposed Artist World transcription and visual scene analysis. **It does not execute a speech/vision model, upload media to providers, or generate a transcript, scene or finished video.**

## Acceptance boundaries

1. Only authenticated production editors can inspect, queue, cancel, recover or explicitly retry work. Admin write requests use the existing session and CSRF controls.
2. The source capture must be **approved_for_edit** and have an immutable server-observed media evidence row. Evidence digest and byte length must match the current Media Library asset.
3. An independent human rights decision for **external_ai** must currently be **cleared**. Contributor self-attestation is insufficient.
4. A unique submission/task constraint prevents duplicate queued processing. Valid tasks are `transcript` (audio/video) and `scene` (image/video).
5. Active jobs are capped at 12 per initiating editor. Each job has up to three claims; each claim has a five-minute lease bound to an opaque per-claim token stored as a SHA-256 digest.
6. Revocation, cancellation, expiry, evidence drift or source mismatch invalidate the lease. A future worker **MUST** check `sf_inference_lease_valid(jobId, token)` immediately before every provider transfer and before accepting model output.
7. Expired leases are marked `interrupted`, not retried automatically, because a provider call may have already happened. Editors must explicitly review and retry.
8. Queue listings contain status and source identifiers only; they never return source paths, private media, dialogue, evidence fingerprints, or lease tokens.

## API

`GET admin/api/story-inference.php` reads the current private queue. `POST` requires normal Admin write authorization, and accepts:

- `{"action":"enqueue","submission_id":42,"task_type":"transcript"}`
- `{"action":"cancel","job_id":17}`
- `{"action":"recover"}` (expire stale leases; no auto-retry)
- `{"action":"retry","job_id":17}` (explicit reviewer decision)

The HTTP API deliberately does **not** expose internal lease claiming, raw media, provider credentials, or a model execution action.

## Deployment and next sections

Run `upgrade.php` on a backed-up installation to apply migration 042. Test on the real HostGator MySQL and uploaded media before exposing production AI processing. Existing private uploads are not automatically enqueued; rights clearance must first be recorded separately.

**88D3B** should build an internal supervised inference worker with configured provider selection, bounded spend, secure media handling, and explicit reviewer-visible draft results. Add separate tests for uncertain provider outcomes, network failures, consent revocation during processing, and provider-returned untrusted content. **No AI provider traffic is authorized by 88D3A alone.**
