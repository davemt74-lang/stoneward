# Section 91B1 — Stonefellow Agent integration coverage (v1.3.161)

## Findings and boundaries

Stonefellow already has an Agent tool registry, typed skill inventory, customer/Admin Agent Brains, intent router, Admin navigation, a reviewed proposal ledger, and specialist production workflows. None of these should be rebuilt as a competing Agent framework.

- **30 registered tool contracts** and **29 corresponding skill definitions**, including one deliberately hidden internal audit tool. The verified counts come from the runtime registry, not a duplicated hard-coded list.
- **23 Admin module routes** expose helpful UI navigation. Those routes do not, by themselves, provide record-level reads or writes through conversation. All 23 are explicitly reported as `navigation_only`.
- **Five existing Admin proposal executors** are eligible for exact reviewed approval: campaign draft, lifecycle automation draft, customer-order follow-up note, Stem Studio automation, and catalog metadata update. They still require owner-scoped authorization, approval and a separate execution step.
- **Consequential publishing and sending** are owner-controlled workflows. Their presence in the registry must never be interpreted as permission for an LLM to execute them.
- **Customer/private and Admin roles are separate**. Neither a skill manifest nor a future VP3 connection may widen the caller's authority.

## Implemented in 91B1

`api/agent-integration-coverage.php` produces a machine-readable integration-coverage report from the live registries and live Admin module definitions. Each tool records its actual registry policy, skill bindings, approval requirement and a **conservative classification**. The report never guesses that a declared PHP handler successfully ran. All module routes explicitly disclose that record-level reads and writes remain unverified.

An authenticated Admin can fetch the opt-in read-only diagnostic from `admin/api/agent-tools.php?audit=1`. The established `agent-tools.v1` response stays unchanged for existing callers without `audit=1`. No public or customer API exposes the Admin report, and the audit performs **no writes, database migrations or provider calls**.

The release gate checks registry/skill parity, scope isolation, owner-only consequential actions, 23-route classification, 5-governed-executor allowlist, unknown-intent rejection, authenticated opt-in route and no execution on the diagnostic endpoint.

## Prioritized gaps for following sections

1. **91B2 — Authorized knowledge:** extend actual, permission-filtered Admin data reads beyond the current summary and domain-specific handlers; start with catalog, releases and artist-facing content. Show real source evidence, not generic navigation suggestions.
2. **91B3 — Reviewed operations:** for supported domains, connect specific input schemas to existing destination endpoints and the established exact-approval ledger. Never use dynamic PHP/SQL execution, and never auto-publish, send or charge.
3. **91B4 — Conversational execution:** make the same registered reads and governed proposals reachable through text and the continuous voice flow without changing existing 91A barge-in and audio semantics.
4. **91B5 — Certification:** end-to-end customer/Admin role tests, retries/idempotency, denial cases, source-of-truth comparisons and browser/hardware validation. A 10/10 automated score does not imply production microphone or real database integration was tested.

## Future VP3 integration

The API's `scope` is explicitly `stonefellow_only`. No Gelato or VP3 code, data, identity token, endpoint or HomeServer synchronization is touched. Eventually a separate, authenticated and revocable VP3 adapter can request **approved Stonefellow capabilities** under a delegated actor identity. Stonefellow continues to own its permissions, audit trail, database and actions.

## Scorecard (91B1 scope only)

1. Live registry enumeration and no duplicate tool engine.
2. Skill coverage, orphan and invalid-binding detection.
3. Actual role policy preserved.
4. Navigation distinguished from record access.
5. Owner workflows distinguished from Agent proposal executors.
6. No direct Agent execution granted.
7. Admin-only and opt-in diagnostic endpoint.
8. Invalid/new module intentions detected, fail closed.
9. No runtime mutations or provider calls.
10. Release-gate regression coverage and explicit follow-on gaps.

Only when these checks pass is **91B1 audit integration** rated 10/10. The comprehensive Stonefellow operational capability goal remains open until the later sections pass their own functional acceptance tests.
