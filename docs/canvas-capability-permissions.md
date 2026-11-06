# Canvas capability and permission foundation

Merged in v1.3.294 ([PR #346](https://github.com/davemt74-lang/stoneward/pull/346), 29 passing checks): the capability/admin policy foundation and remembered viewer consent for the seven existing registered canvas datasets. This is a scoped release unit; the token meter and cross-experience analytics have separate implementation/validation records. Additional pickers, library/provider adapters and the broader security-learning outline remain future work. Image/vision/production expansion remains on hold.

## Capability cards and administrator policy

Cards derive from the existing tool registry and expose stable permission IDs, schema/access-contract versions, descriptions, fields, filters, supported actions, current-account requirements and a data-contract hash. Cards describe a capability; they never grant execution authority.

Admin → Canvas Permissions provides searchable toggles, pending-change counts, explicit Save, a policy revision and the latest 30 change records. The real `admin/api/canvas-permissions.php` requires administrator authentication and CSRF for POST. Saves use a current integer `revision` and boolean `changes` map. Stale saves return 409; unknown permissions/nonboolean values return 422. Policy and administrator/time/before/after history commit atomically. SQLite and MySQL/MariaDB use transactional storage; MySQL tables explicitly use InnoDB. Nested policy writes are rejected before any schema DDL.

The switches control **canvas dataset delivery**, preserving the underlying account, shop, audio and administrator workflows. Authentication, account isolation, CSRF and sandbox protections remain mandatory. Default enabled values preserve compatibility for public datasets. Personal canvas data now additionally requires explicit viewer consent. Reading policy or grants does not initialize their optional storage.

## What this canvas uses and remembered access

Gallery entries show their declared data capabilities and whether personal-data permission is required. The summary contains no creator grants or private rows. The canvas footer's **Canvas access** opens a fresh host-owned review; signed-in viewers receive the review when a newly opened canvas needs personal-data access. They can allow selected categories or continue without personal data. The user dropdown's **Canvas data permissions** lists saved grants and allows revocation, including for deleted or withdrawn canvases.

`api/canvas-access.php` supports:

- GET `?scene_id=N` for an owned saved canvas, or `?gallery_id=ID` for an available approved public entry. The response includes the authoritative context, exact review fingerprint, title, permission descriptions/status and whether consent is missing.
- GET `?mine=1` for the signed-in account's own saved grants.
- CSRF-protected POST `grant` or `revoke` with `context`, current `fingerprint` and explicit `permission_ids`.
- CSRF-protected POST `revoke_saved` with an owned `context_key` and permission IDs. Revocation does not require the original canvas to remain available.

Grants bind the current account to a server-owned canvas lineage and declared field/access-contract scope. Equal or narrower scopes reuse valid consent; expanded categories/fields require approval. Public gallery use and independently saved remixes each have separate consent contexts. Caller-supplied lineage values are ignored. Legacy rows resolve their previous-ID chain; deleting an earlier revision preserves the resolved root before severing the chain.

Consent fingerprints bind current stored code, scopes, policy revision, viewer identity, privacy epoch and a monotonic account grant revision. Stale Allow requests cannot restore revoked access. Account locking serializes consent changes with privacy resets/closure; policy locking serializes native MySQL grant checks with administrator disablement. Consent and revision changes commit together. Re-enabling administrator availability does not restore user-revoked consent.

## Host enforcement and lifecycle

`sf_agent_canvas_data_tools()` excludes disabled datasets from model discovery. `sf_agent_canvas_data_for_scene()` re-resolves trusted stored context before private delivery, rejects mismatched markup, withholds unapproved personal rows and checks policy/grants again after domain reads. Missing consent yields empty rows with `consent_required`. Direct authored markers or omission of stored context cannot bypass consent. Private delivery inside an outer transaction yields `unavailable`, avoiding authorization checks against an older repeatable-read snapshot.

Normal saved-scene, generated-scene, workflow candidate/apply and gallery-open responses carry fresh viewer-specific access metadata. The public UI guards permission requests and refreshes against account, canvas and active-build changes. Permission selection/review performs no model call and deducts no AI tokens. Authored canvases receive data through existing host bindings; they cannot grant themselves permissions.

Disabling/revoking access affects subsequent host deliveries. It cannot recall bytes already delivered to a browser/provider. History clearing and account closure purge grants and grant-revision state through the existing privacy transaction; exports include only the requesting account's grants. Late requests cannot restore access to cleared canvases.

## Acceptance evidence and score

**10/10 against the automated acceptance criteria for these two implemented phases**, following independent review and correction of transaction initialization, stale-request replay and legacy-lineage cases. This score does not certify every remaining outline feature or deployment environment.

- Core policy checks: `tests/canvas-capabilities.php` (21).
- Consent tests: `tests/canvas-access.php`, including scope expansion/contraction, trusted lineage, independent copies, direct-call denial, replay protection, legacy deletion, workflow response metadata and privacy clearing. This suite includes existing project fixture checks.
- Real policy HTTP: 20 checks; real consent HTTP: 27 checks.
- Native MariaDB 10.11.14: 15 policy/consent checks, including independent workers, stale conflicts, InnoDB transaction safety and injected-failure rollback.
- Real PHP + production consent UI: 21 checks at 390/1440 widths, actual login/cookies/CSRF, own-data delivery, remembered reload, revoke, account manager and no provider calls. This is an isolated host harness, not full application E2E.
- Additional browser fixtures cover admin policy controls, consent focus/account races, malformed responses and disabled/orphan grants. Gallery browser checks: 52. Existing project, workflow, privacy, gallery, resource and canonical music suites remain regression coverage.

Commands/environment requirements are in [validation notes](canvas-permission-validation.md). Physical iPhone testing and live HostGator deployment were not performed. Native MariaDB validates the MySQL SQL path; it does not cover every MySQL version or host configuration. Source implementation remains separate from PR/merge/deployment status.
