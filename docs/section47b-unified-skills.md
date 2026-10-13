# Section 47B — Unified skill registry and automatic discovery

## Design
The existing 17-tool Section 44 registry remains authoritative. 16 public/customer/Admin-visible tool descriptors now have named skills. `agent_brain_log` stays internal. This is a **declarative skill inventory** backed by existing registered tools, not a second execution engine or model-callable PHP dispatcher.

## Available contexts
- **public:** four published discovery/queue capabilities; no private fan or Admin tools.
- **customer:** published capabilities plus favorites, playlists and configured voice/LLM/JEV services. Requires existing authenticated Customer Agent endpoints; destination handlers still own authorization, tokens, privacy and consent.
- **admin:** published capabilities plus CRM/campaign reads and reviewed Admin drafts/publishing/outreach manifests. Owner-controlled send/publish actions remain non-executable from Agent orchestration. Admin skill listings never include personal Customer favorites or playlist management.

## Integration
- `api/agent-orchestration.php` resolves mapped intents from `api/agent-skill-registry.php`, preserving legacy `review_actions` as a non-discoverable internal audit contract.
- Customer Brain GET includes `brain.skill_inventory` after authenticating a customer.
- Admin Brain snapshot includes `skill_inventory`, and the already-protected Admin tools endpoint returns a `skills` field without removing `tools`.
- Every manifest returns tool ID, kind, context, approval requirement, execution location and explicit `may_execute_directly=false`; no handler, owner path, secrets or raw customer records are included.

## Acceptance and limitations
`php tests/section47b-skill-registry.php` verifies mapping coverage, role filtering, old contracts, exact approval boundaries, no direct execution, HTTP auth hooks, and the absence of dynamic dispatch. Release CI must run it.

Discovery alone cannot perform a tool action. Live integration, retries, bounded mission planning, exact owner approvals, and provider checks remain later sections.
