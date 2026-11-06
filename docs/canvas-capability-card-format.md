# Canvas capability card format

Status: proposed specification within the approved planning scope. Implementation remains paused. This describes the information supplied to Coding, Testing, Repair and Security; it does not register, install or enable a capability.

## Purpose

Give every agent a consistent, concise way to understand what a tool, library or data source can do, whether it fits the current project, how it is authorized and how its behavior can be verified. Cards describe capabilities without prescribing a canvas layout or preferring a particular library.

The host owns the authoritative registry. Agent-readable cards are safe projections of that registry. A card, model response or user-supplied identifier cannot independently authorize an operation.

## Required fields

| Field group | Required information |
| --- | --- |
| Identity | Stable registry ID, display name, kind (`library`, `data_source`, `host_action`), card schema version and card revision. |
| Purpose and fit | Plain-language capabilities, suitable project requirements, constraints, overlaps and relevant alternatives. Avoid universal preference scores. |
| Lifecycle | Proposed, under review, approved, enabled, retired or revoked; responsible administrator and decision references. Approved and enabled are distinct states. |
| Version and integrity | Exact package/API/adapter versions as applicable; bundle, dependency and schema hashes; immutable dependency manifest; source and release references. |
| Interface | Typed input/output schemas, permitted methods, examples, required fields, units, maximum supported payloads and explicit unsupported cases. |
| Access | Public, current-account or separately consented scopes; declared read/write side effects; scope-expansion conditions; host enforcement and grant-revocation behavior. |
| Delivery | Reviewed host adapter or local bundle identity; permitted upstream destinations and assets; credential handling reference. Omit secret values and arbitrary destination overrides. |
| Compatibility | Renderer/SDK/CSP requirements, browser/device coverage, additional features such as WASM/workers, known limitations and required fallbacks. |
| Lifecycle handling | Initialization, update, cancellation, Stop, teardown, subscriptions and ownership of graphics/audio/network resources. |
| Cost and usage | AI token implications, provider usage units, administrator-configured budgets, cache/deduplication behavior and applicable quotas. Keep estimates separate from actual receipts. |
| Data provenance | Provider, source identifiers, valid/observed/forecast time, fetch time, timezone, units, attribution, licenses and asset-specific reuse requirements. |
| Failure contract | Typed unavailable, denied, revoked, stale, unsupported and rate-limited outcomes as applicable; allowed recovery paths and retry/idempotency rules. |
| Evidence | Verification status, exact tested hashes/versions, environment, fixture set, test results, review findings and unresolved coverage. Unknown compatibility remains unknown. |
| Documentation | Version-matched API reference, short verified usage examples, known integration pitfalls and provenance of documentation updates. |
| Change impact | Relevant consumer/dependency identities, audit invalidation conditions, migration notes, rollback/revocation procedures and affected-project lookup. |

Fields that do not apply are explicitly marked not applicable. A required value that has not been verified remains unresolved; it cannot be filled with a plausible model guess or converted into an enabled status.

## Agent and host views

The planning view contains the capability description, fit, safe interface summary, access/cost implications, compatibility, lifecycle status and verification coverage. Full version-matched references are available when relevant to the project; the agent need not ingest every library's documentation for every request.

The host view additionally holds enforcement configuration, immutable manifests, credential references and administrator decisions. The public What this canvas uses summary is derived from the validated selected capabilities and permission scopes. It exposes understandable tool/data access information without private account records or internal secrets.

External documentation, examples and data remain untrusted reference content. They may inform an implementation but cannot modify registry policy, grant access, instruct the auditor or enable a new dependency.

## Use in the project loop

1. Interpret the project's requirements and retrieve relevant enabled cards and native-browser options.
2. Compare project fit, known compatibility, supported interfaces, lifecycle obligations, costs and permissions using recorded evidence.
3. Select a suitable combination and add immutable card/dependency revisions and expected behavior to the project contract. Record a brief rationale rather than private model reasoning.
4. Apply existing project approvals and remembered grants. Request a new decision only when the relevant access scope expands.
5. Build and verify against the exact selected versions and fixtures; reject stale evidence after a candidate or dependency change.
6. Recover or repair within the card's failure, authorization and cost contract. An unavailable capability cannot silently become an arbitrary network fetch or a different unapproved experience.
7. Record privacy-safe outcomes as candidate knowledge improvements. Reviewed evidence and administrator-approved policy changes govern future card updates; usage frequency alone does not make a library the default.

## Illustrative card summaries

The identifiers below illustrate the proposed naming format. They are not existing registered tool IDs. No example claims a completed compatibility or security review.

| Item | Library example | Public data example | Current-account example |
| --- | --- | --- | --- |
| Proposed ID | `library.meyda` | `data.weather.nws` | `account.listening.read` |
| Purpose | Extract audio features for an experience that needs them. | Retrieve supported U.S. forecasts, observations or alerts for a selected location. | Supply the current viewer's authorized listening statistics. |
| Interface outline | Authorized sample arrays or a reviewed audio adapter; selected feature names; documented numeric outputs. | Validated selected location, supported product and units; typed values with source times and freshness. | Allowed track/release filters and date range; bounded documented statistics. |
| Access | Audio use follows the host/player scope; microphone access is a separate capability. | Explicit public location or viewer-consented location context; no implicit precise-device access. | Current-account identity resolved by the server; arbitrary account IDs are not inputs. |
| Delivery | Exact reviewed local bundle and dependencies; existing media/audio boundaries. | Trusted PHP adapter, reviewed NWS destinations, identified requests and caching. | Existing authorized host/data adapter; no direct private database or cross-account access. |
| Usage | Extraction itself requires no model call; measure device resource cost. | Retrieval itself requires no model call; respect provider usage and cache rules. | Read-only retrieval itself requires no model call; apply request limits and data minimization. |
| Failure examples | Unsupported audio path, denied access, invalid input or canceled analysis. | Unsupported coverage/product, stale data, rate limit, provider outage or revoked location grant. | Authentication/grant required, invalid filter, revoked session or unavailable data. |
| Evidence still needed | Pin a release, verify the audio adapter and test performance/cleanup on target devices. | Verify each enabled product schema, coverage, freshness, outage behavior and location privacy. | Pin the actual existing interface/schema and verify guest/account separation and late-result rejection. |

## Card acceptance checklist

- The host validates registry IDs, versions, typed parameters and permissions independently of agent claims.
- Selected manifests match exact code/assets/dependencies and the candidate under review.
- Missing, revoked, disabled or incompatible entries produce explicit outcomes.
- The current viewer's grants never transfer through public snapshots, account changes or independent remixes.
- Source times, units, attribution and costs remain machine-readable and user-understandable where relevant.
- Actual positive, negative, lifecycle, mobile and failure-path evidence supports each enabled integration.
- Updates identify affected projects, invalidate incompatible audits and preserve the established rollback/withdrawal boundaries.

## Administrator permission controls

The user selected an **Admin Permissions** section with a control toggle for each permission. This is approved planning scope; implementation remains paused.

Generate permission rows from the authoritative capability registry so new reviewed capabilities appear in the same controls rather than relying on a manually maintained UI list. Each row shows a stable permission ID, readable name, description, data/action scope, enabled state, eligible account context, consent requirement and affected capabilities/projects where available.

- Provide a global allow/disable toggle for each permission. Keep read access, write/action access and optional device capabilities separately controllable. Group related rows for readability while preserving individual controls.
- Cover public artist/media reads, public data providers, current-account data reads, optional device location/microphone/camera capabilities, and authorized side-effecting host actions as those capabilities are registered. Library-version availability remains controlled by the library registry and does not grant data/action permissions.
- Make guest eligibility and signed-in eligibility clear per row. Administrator enablement makes a permission available under policy; current-user authorization, required consent and browser permission still apply.
- Keep fundamental host protections enforced: account isolation, authentication, CSRF, credential secrecy, sandbox boundaries and exact-snapshot publication checks cannot be switched off through these controls.
- Support search, useful groups, readable status and accessible keyboard/touch operation without nested page scrollbars. Show pending changes and available impact information before an explicit Save action.
- Save changes through authenticated administrator/CSRF controls, validate them server-side, version the policy and preserve an audit record of who changed which permissions and when. A model cannot change these controls by returning new card content or instructions.
- Enforce the current policy on every affected operation. Disabling a permission blocks subsequent use, cancels or invalidates affected pending deliveries where applicable, and cannot be bypassed by an old card, saved canvas or remembered grant.
- Re-enabling a permission does not create user consent, restore a user-revoked grant, authorize broader access or publish withdrawn content. Existing valid grants can be reused only within their original account/canvas/scope boundaries.
- Keep purchase, account-change and token-spending confirmation rules attached to their host actions. Enabling an action permission alone is not an execution receipt or approval for an individual transaction.

Acceptance includes direct-API bypass attempts, stale policy versions, disabled permissions during active work, guest/account separation, consent denial/revocation, independent remix boundaries, administrator role/CSRF enforcement and policy-change audit history. Coordinate the migration/defaults with existing feature permissions and compatibility checks before deployment.

The resulting matrix maps each permission to its admin toggle, applicable account context, user-consent requirement, host enforcement point and revocation behavior. It remains planning work until implementation resumes.
