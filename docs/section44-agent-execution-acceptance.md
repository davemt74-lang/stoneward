# Stonefellow Section 44 — Agent Actions, Campaign Builder & Automation

**Release:** v1.3.43 · **Schema target:** 1.3.34 · **Migration:** 035  
**Base:** single-artist Stonefellow v1.3.42. This is not VP3 Cloud/HomeServer.

## Build inventory and scored acceptance

Each subsection is considered **10/10 only when the stated acceptance test, the full historical release gate, and the release integration gate pass on the same reviewed code**. This score refers to the concrete, automated acceptance scope, not live provider credentials or browser accessibility audits.

| Section | Shipped capability | Acceptance file |
| --- | --- | --- |
| 44A | Descriptive server-authoritative tool registry, admin-only view, explicit read/write/consequential scopes | `tests/section44a-tool-registry.php` |
| 44B | Durable reviewed proposal ledger, schema migration 035, idempotent request keys, strict admin + CSRF | `tests/section44b-reviewed-proposals.php` |
| 44C | Existing Admin Chat → Campaign Builder workbench, three distinct prepare/approve/save steps | `tests/section44c-campaign-workbench.php` |
| 44D | Segment-aware, aggregate-only consent-qualified CRM audience preview, no contact identifiers | `tests/section44d-audience-research.php` |
| 44E | Newsletter/download/discount/VIP draft templates and copy cues grounded in published Stonefellow content | `tests/section44e-published-campaign-copy.php` |
| 44F | Only `campaign_draft_save` executes; exact content hash, expiry, rate caps, restricted publishing/sends | `tests/section44f-execution-governance.php` |
| 44G | Per-admin Agent Brain action timeline, audited status and actual Campaign Builder result | `tests/section44g-action-timeline.php` |
| 44H | Cancel never-started requests, stale-operation manual review, terminal no-replay reconciliation | `tests/section44h-recovery.php` |
| 44I | Responsive dialogs, keyboard labels/focus, Admin text/voice command access to existing controls | `tests/section44i-mobile-chat.php` |
| 44J | Real production campaign-save integration, final version/schema gates, merged-main certified ZIP with SHA-256 | `tests/section44j-live-campaign-save.php`, `tests/section44j-release.php` |

**Post-build independent code audit:** `tests/section44-audit-hardening.php`. Tests active execution/reconciliation races, stale approval prevention, full approved payload visibility, proposal size/rate bounds, replay-safe audit logs and persisted status.

## Exact authority boundaries

- **Scope:** administrators propose one campaign **draft**, review all source-normalized fields including body and node graph, approve a SHA-256 hash using the typed phrase `APPROVE`, then separately request saving.
- Only server-authenticated administrators may prepare, read, approve, cancel, reconcile or execute a proposal. POSTs require the existing administrator CSRF protection.
- No Agent approval bypasses the canonical Campaign Builder graph validator, or the canonical `sf_campaign_save` routine. Every Agent save forces `id=0` and `status=draft`.
- **No Agent sending, purchasing, publishing, live CRM editing, campaign deletion, unsolicited outreach, or automatically scheduled sends.** The existing owner-facing Admin workflows retain these permissions.
- CRM previews return summary counts, without contact IDs, email addresses, or private individual profiles. Email eligibility is re-evaluated by the existing campaign send path.
- Draft source suggestions use published releases, publicly eligible recordings and publicly visible shows; source suggestions are not AI-generated claims.

## Durable operations, failure handling and privacy

- Database proposals are uniquely keyed by `admin_user_id + request_key`. A changed payload with the same key is rejected. Completed requests return their original result without a second campaign insert or duplicate audit event.
- Proposals have a 128 KiB normalized payload cap and 40-new-proposals-per-hour per-admin cap; existing idempotent keys remain readable.
- Unapproved proposals expire for confirmation after 24 hours; confirmed approvals expire before execution after 1 hour.
- A single conditional SQL claim changes `approved` → `executing`. Only an executing row can become `completed`; failed or ambiguous saves become `review_required` and are never retried automatically.
- In-flight requests cannot be reconciled until a two-hour safety interval has passed. Owner must inspect the canonical Campaign Builder first; `RECONCILED` marks acknowledgment, not rollback or a claim that a save occurred.
- `CANCEL` is restricted to unclaimed `proposed`/`approved` requests. Reconciliation and cancellation are audited.

## Deployment and verification

1. Back up your application and database before replacing live PHP files.
2. Deploy the complete v1.3.43 ZIP, not a patch-only candidate.
3. Authenticate as the existing super administrator and open `upgrade.php`. Apply migration **035** through its existing backup/lock/preflight workflow (new schema target **1.3.34**).
4. Check the Admin → Campaigns → Agent draft workbench and Agent Brain → Agent actions.
5. Prepare, approve and save a disposable test draft, inspect it in the original Campaign Builder, and ensure no email was sent. Remove the disposable draft using the existing Admin workflow if desired.
6. Verify admin-only permissions, mobile dialog behavior, and any deployed LLM/voice services that matter to your installation.

The automated gates validate PHP and JavaScript syntax, existing regressions, SQLite proposal/campaign persistence, permissions, retry handling, version synchronization and ZIP integrity. They do **not** certify live SMTP sends, production MySQL migration rehearsal, external LLM provider availability, or browser-driven accessibility testing. Those require deployment-environment verification.

## Source history

Sections 44A–44I were merged individually through PRs #61–#69; the post-build hardening review merged in #70. Section 44J comprises final version synchronization, expanded integrated acceptance and the certified deploy artifact.
