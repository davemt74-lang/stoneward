# Section 47A — Agent capability audit and integration baseline

**Source baseline:** v1.3.45, merged Section 46 dual-Brain release.  
**Audit location:** `api/agent-capability-audit.php` (PHP library; no public HTTP route).  
**Acceptance:** `php tests/section47a-capability-audit.php`, required by `.github/workflows/release-gate.yml`.

## Inventory — existing capabilities (not a new execution grant)

The Section 44 server-owned registry `api/agent-tool-registry.php` already declares 17 tool metadata entries. Section 46's shared `api/agent-orchestration.php` maps specific Customer and Admin intents to some of them. Section 47A validates that existing inventory; it does **not** create a second tool engine.

| Authority / consumer | Registered tools | Implementation / control |
| --- | --- | --- |
| Public customer context | `public_catalog_search`, `public_site_search`, `recommend_public_track`, `queue_track` | Server discovery and client-local player queue; published content only |
| Signed-in customer context | `favorite_track`, `create_playlist` | Existing authenticated endpoint; confirmation required |
| Admin reads | `crm_aggregate`, `campaign_aggregate`, `campaign_graph_validate` | Existing server-side owners; Admin authentication enforced by route |
| Admin reviewed writes | `campaign_draft_save`, `automation_draft_save` | *Only* confirmed draft proposals on explicit executor allowlist |
| Admin consequential actions | `campaign_publish`, `campaign_send_email` | Owner-controlled governed UI; not direct Agent executors |
| Metered provider capabilities | `elevenlabs_voice`, `llm_response`, `jev_routing` | Provider configuration, entitlements and limits remain authoritative |
| Internal audit | `agent_brain_log` | Server-only; never a discovered or executable tool |

**Scope distinctions:** `fan` means public/non-private fan context. `signed_in_fan` is available to authenticated customers or authorized Admin runtime roles, but ownership/consent still comes from the destination endpoint. `admin` tools must not be exposed to fans. `server` tools must never appear in user inventories.

## What Section 47A now checks

1. Contract fields, strict types, known scopes/kinds/execution locations, valid handler identifiers and labels.
2. Safe repository-local tool owner paths and physical existence of registered owner files.
3. Every write/consequential capability requires an explicit confirmation flag; consequential entries stay Admin-scoped.
4. Exact Guest/Customer/Admin inventory and `sf_agent_tool_allowed` decisions, including hidden internal tools.
5. Zero unconfirmed executable tools, with a narrow, tested *confirmed* Admin proposal allowlist (`campaign_draft_save`, `automation_draft_save`). Sending and publishing remain excluded.
6. Adversarial mutation tests that deliberately break scope, confirmation, handler, owner path, execution location, and contract shape.
7. The real release gate runs the functional test, not only PHP lint.

The report is diagnostic, not a permission grant. It does not execute a tool, call a provider, mutate the database, or create a public route. An inventory entry does not establish actual endpoint permissions.

## Integration gaps to address before adding autonomy

- **47B — Registry architecture:** map legacy PHP handler routes, client-local actions and provider capabilities to stable typed skill manifests. **Do not** expose an unrestricted dynamic PHP function caller.
- **47C — Planning:** couple customer/admin goal planning to the existing owner-scoped orchestration contract and persist proposed steps. Enforce budgets and retries at the action ledger.
- **47D–47E — Context:** retain separate Customer and Admin Brains from Section 46; never use the Admin inventory as permission to read another customer's memories, conversation or personal data.
- **47F — Proactivity:** reuse explicit preferences/consents and existing campaign/lifecycle governors. No automatic external outreach simply because a model proposes it.
- **47G — Specialists:** delegation must narrow, never widen, the original actor's scope; include provenance and takeover/revocation.
- **47H — Canvas:** show selected tools, source evidence, drafts and approval state. Avoid silent writes, sends or publishes.
- **47I — Recovery:** exact approval scope, idempotency keys, authorization rechecks, human-visible error states and tests for partial failure/replay.
- **47J — Certification:** release ZIP only from merged green main, with deploy checksum and migration-safe upgrade checks.

**Limitation:** Section 47A enumerates the registered tool contract, not all possible PHP functions, admin pages, background jobs or integrations. It validates handler *declarations* and owner *files*, not that every external provider is configured or that every live deployment is healthy. Those require later integration and live-environment tests.
