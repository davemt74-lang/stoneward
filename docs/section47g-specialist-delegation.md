# Section 47G — Governed specialist delegation

Specialist assignment contracts are derived from the existing 47C persisted owner-scoped mission plan, not a model-supplied capability ID. A maximum of four assignments is allowed per mission, each with one read budget, a named registered skill, a deterministic assignment ID, an explicit status, and **no direct write permission**.

Currently specialists represent **logical governed workstreams**. Existing verified CRM and journey aggregate read evidence can be attached to completed Admin read workstreams. Customer catalog/recommendation work remains pending until its actual authorized read path supplies results. Publishing, messaging, drafts and playlist changes remain owner-reviewed and are never executed by a specialist. No independent external LLM subagents or background browser sessions are claimed by this phase.

Every delegation loads the source plan using scope, authenticated owner and mission ID. A forged tool ID, changed approval flag, foreign plan, forged execution flag, or already-completed write is rejected. The Customer and Admin Brain canvases display statuses without granting the browser execution permissions.

There is no second task or approval database; restart/replay is deterministic from stored plans and account history clearing revokes delegations. All client and Admin private data boundaries remain unchanged.

Acceptance: `tests/section47g-specialist-delegation.php` exercises real SQLite owner separation, cross-Brain isolation, idempotency, tampering, approval controls, and both UI surfaces. It is part of the full release gate.
