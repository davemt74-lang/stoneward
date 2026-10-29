# Section 91B6A — LLM-First Agent Orchestration

The public Stonefellow agent uses its configured Claude or OpenAI provider to interpret requests against bounded recent dialogue, current page and player state, published song title/ID candidates, and a role-filtered list of available skill/tool descriptions. The user expressly approved this limited context disclosure. It never sends API credentials, private master files, or payment details as orchestration context.

The response is a structured **proposal**, not an instruction to the application. The server accepts only allowlisted route identifiers; it then runs the existing policy to determine what is authorized. For named playback, a model-selected ID must exist uniquely in the published catalog and pass speech/title identity validation. The server returns an `audio_resolution` receipt only for this accepted ID, and the public browser cross-checks the ID and exact title against its live catalog before attempting playback. The player itself must report a real start before the agent may claim playback succeeded.

For example, a voice transcript of `play genius of on human kind` can map to the published title `Genius of Unhumankind`. A mistaken model selection of the currently playing `Subtle Surrender` cannot pass the validation gate. An absent or stale browser catalog receipt also blocks playback without changing the current song.

Ticket, merchandise, vinyl, membership, commerce and account intents share the LLM's same route selector and permission-filtered tool descriptions. They still use the existing route policy: purchases, reservations, fulfillment, publication, refunds and account mutations are never implicitly executed. Relevant UI controls and human confirmations remain required.

When an LLM is unavailable, malformed, or cannot resolve an ID, the existing local and JEV routing remains a conservative fallback. The fallback does not pretend that a failed track match succeeded.

## Follow-on scope

- Wire the same orchestrator into the Admin-specific chat experience, with authenticated, narrowly scoped private read tools and reviewed writes.
- Add structured target arguments and receipts for page navigation and multi-step merch/ticket/vinyl flows, rather than relying on legacy route-text parsers.
- Instrument actual player, TTS, microphone/echo, and barge-in state transitions so the model's speech never becomes the next user's prompt.
- Live-test configured providers and actual published audio in a signed-in browser. Offline source/CI checks cannot certify that external providers or browser playback work in the deployment.
