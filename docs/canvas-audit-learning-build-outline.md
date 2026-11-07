# Canvas audit and learning build outline

Status: implementation resumed. Capability/admin-policy and remembered-consent phases merged in [PR #346](https://github.com/davemt74-lang/stoneward/pull/346), with 29 GitHub checks passing. See [foundation evidence](canvas-capability-permissions.md). The [account token meter](account-token-meter.md) merged in [PR #347](https://github.com/davemt74-lang/stoneward/pull/347), with all 26 GitHub checks passing. [Canonical cross-experience listening](canvas-listening-attribution.md) merged in [PR #348](https://github.com/davemt74-lang/stoneward/pull/348) as v1.3.296 with all 30 GitHub checks passing. v1.3.297 adds [playlist selection and artist publication](canvas-resources.md). The remaining phases stay planned. Image/vision/production expansion stays deferred.

## Current scope hold

The user has deferred image/vision/production expansion. Keep existing image selection, generation and playback-related features compatible, but do not implement new vision, reference-cast/storyboard systems, production-editor integrations or `/production` during the core canvas work. Retain those ideas as future scope. New image-analysis and media-palette work is deferred with that expansion unless the user explicitly brings it back into scope.

The [implementation readiness audit](canvas-readiness-audit.md) maps the core plan to inspected source. It is a source review, not a live deployment or passing-test certification.

## Purpose and existing foundation

Build a general audit and improvement process for agent-created canvas experiences. Assess their behavior, data access, and fit to the user's request while preserving freedom in presentation and interaction design. User examples are validation cases, never mandatory templates or fixed intent routes.

The starting point is Stonefellow v1.3.293: private canvas history; approved project proposals; Coding, Testing and Repair records; protected preview execution; host-mediated audio/data access; exact gallery snapshots; administrator publication approval; independent private remixes; and public creator attribution.

The new process extends those interfaces and owner boundaries. It must preserve existing playback, image, catalog, editing, visibility, approval-preference and token-accounting behavior.

## Shared project contract

Coding, Testing, Repair and Security use the same versioned contract:

- The actual approved user brief, stated assumptions, expected behaviors, and acceptance checks.
- Selected canvases, artist resources, music and image references, with their authorization and ownership scopes.
- Selected external data sources, including weather, NASA, OpenStreetMap, NOAA Space Weather, USGS earthquakes, NOAA Tides & Currents and Wikidata, with relevant location mode, units, freshness, provenance, media rights and permission scope.
- Required tools, their schemas, data scopes, side effects, permitted actions and token costs; approved scripts/libraries with exact dependency versions and bundle hashes.
- Exact code and asset hashes, source lineage, SDK/tool versions, model configuration, and test environment.
- For a revision, the requested change and all other approved requirements that must continue to hold.

The contract records requirements without imposing a visual layout. A material ambiguity triggers one focused question; otherwise the agent states a reasonable assumption and proceeds within authorized scope.

The [capability-card format](canvas-capability-card-format.md) defines the shared reference for tools, libraries and data sources, including illustrative library, public-data and current-account examples. It is a planning specification; no cards or integrations are enabled by the document.

## Unified capability discovery and build process

Status: approved build scope; implementation has resumed incrementally. Extend the existing project workflow rather than creating a parallel build engine.

1. **Discover:** Read enabled capability cards and authorized resource inventories. Existing `/images`, `/music`, `/canvas`, `/merch` and `/tickets` selections remain supported. Add planned `/stories`, `/playlists`, `/production`, `/lyrics`, `/knowledge`, `/me` and `/data` pickers, plus the unified command menu and resource inspector. The agent can also discover suitable resources itself; manual selection and command phrases are not prerequisites for a project.
2. **Plan:** Interpret the original request, inspect selected resources and relevant authorized history, compare native code and approved libraries, and present a concise project concept. Record assumptions, requirements and capability choices in the shared project contract. Do not turn examples into fixed routes or inject unrequested controls or prompt text.
3. **Approve:** Present What this canvas uses, personal-data scopes and expected AI usage or meaningful cost uncertainty. Follow the existing per-account approval preference. Reuse valid consent for unchanged or narrower scopes; ask again only for expanded access or a materially changed project. Administrator enablement does not grant viewer consent.
4. **Build:** Generate the requested experience through authorized host interfaces, with visible progress, per-user usage accounting and Stop controls. Keep the working canvas until the candidate is ready; do not introduce fixed generation deadlines.
5. **Verify and apply:** Bind functional, visual, mobile, playback, security and privacy evidence to the exact candidate. Repair against the same requirements, obtain fresh applicable evidence, and replace the active canvas only after host acknowledgment. Preserve the previous working version on failure.
6. **Learn:** Use authorized feedback and verified findings to propose improved capability guidance and audit checks. Keep observations separate from hypotheses; policy changes follow independent review and administrator approval. Untrusted resource contents cannot change agent instructions or permissions.

### Resource pickers and Data Explorer

All pickers attach stable, server-validated references to the draft/project contract. Selection alone starts no build, provider job or AI charge and grants no additional access. Omitted selections retain the established revision behavior; explicit clearing is available. At build and runtime, revalidate availability and current-viewer authorization. Return explicit missing, withdrawn, denied and stale states rather than silently substituting resources.

| Entry point | Resource scope | Status |
| --- | --- | --- |
| `/images`, `/music` | Existing authorized image and track/album inventories | Existing interfaces; extend through the shared contract |
| `/canvas` | My Canvases and administrator-approved Public Gallery entries | Owned picker and separate gallery exist; plan a unified picker with ownership and attribution preserved |
| `/merch`, `/tickets` | Published artist merchandise and ticket offers | Existing searchable multi-select pickers; retain current contracts |
| `/stories` | Published artist stories, with authorized related songs, releases and media | Planned searchable multi-select picker |
| `/playlists` | Current-account playlists and explicitly published artist playlists | Searchable multi-select picker with versioned references |
| `/production` | Authorized AI production/video-editor projects and available project media | Planned integration; editor interfaces and permission mapping require inventory |
| `/lyrics` | Available authorized song lyrics and timed lyrics, with draft/public status respected | Planned picker; works without playback |
| `/knowledge` | Approved artist background, production notes and other authorized knowledge | Planned picker; private/admin material excluded from public results |
| `/me` | Current viewer's listening history, purchases, tickets and supported preferences | Planned account-data picker with separate scopes per data category |
| `/data` | Enabled artist and approved public-data capabilities, including weather, maps and NASA | Planned Data Explorer |

The Data Explorer shows available fields, safe example responses, required permissions, location choices, units, source/freshness, attribution and usage limits. Samples are labeled fixtures or dated observations, never presented as live facts. Private examples contain no other account's data. The authoritative registry and Admin Permissions controls determine availability; cards cannot bypass disabled permissions. Users may attach a source directly, while the agent may select appropriate capabilities during planning.

### Lyrics, knowledge and current-user data

`/lyrics` allows selection of authorized lyrics independently of the current player. Preserve canonical song identity, source/provenance, publication status, corrections and timing availability. Do not invent missing lyrics or treat untimed text as timed transcription. Related song selection does not automatically request playback or transcription.

`/knowledge` discovers approved artist knowledge and production notes through the same host-controlled registry and resource picker. Inventory existing knowledge interfaces before implementation. Published artist material, account-owned material and administrator-only notes remain distinct scopes; a public picker cannot expose internal notes, credentials or private documents. Retrieved text is reference material, never policy or executable instructions.

`/me` groups discoverable current-account data categories in one picker without creating one blanket account permission. Listening history, purchases, tickets and supported preferences each declare their own read scope, fields, costs and consent rules. Show What this canvas uses before access; reuse valid grants and ask only when access expands. Guests receive a sign-in state. Never expose payment credentials, authentication secrets or another account's records. Reading purchase/ticket data does not authorize purchases, transfers, refunds or other writes. Public canvas snapshots contain authorized bindings, not a copy of the creator's private records; each viewer resolves their own data at runtime.

### Resource relationships and attached-resource inspector

Provide typed, server-resolved relationships such as album to tracks, track to lyrics/stories/artwork, and production project to authorized media. Use canonical IDs and version/provenance metadata rather than title matching or invented links. Discovering a relationship grants no access to its target. Resolve each edge under current authorization, with explicit missing/withdrawn results, bounded pagination and cycle handling. Give the agent relevance and payload limits without silently truncating selected inventories. The user's prompt determines which relationships are useful; do not require a track, album or artist-themed widget to build.

Add a project resource inspector showing attached selections, their source and visibility, relevant permissions, version/live-reference behavior, availability and replacements needed. Allow explicit add/remove/replace actions without automatically rebuilding or spending AI tokens. Preserve the existing explicit-clear and revision inheritance behavior. A replacement that changes the approved project or expands access follows the existing project/consent flow. Refreshes and late results must remain bound to current account, project and selection identities.

### Searchable command menu

Expose one searchable menu for the registered picker commands, with short descriptions, keyboard and mobile access, and enabled/signed-in/unavailable states. Generate entries from the authoritative available capability/resource inventory instead of maintaining a second drifting tool list. Preserve free-form chat and existing command behavior; selecting a command opens its picker, not a model invocation. Manual commands remain optional because the agent can discover capabilities during planning. No default canvas widget or fixed intent route is introduced.

Acceptance covers discovery without memorized commands, no implicit spend/build on selection, separate per-category consent, cross-account denial, owner/public canvas separation and attribution, unauthorized relationship targets, missing/version-changed resources, lyrics without playback, inspector edits and stale-response protection, keyboard/touch usability and existing picker compatibility.

### Production selection and editor integration

Reserve `/production` as a planned discovery/selection entry point for the existing AI production/video-editor domain. Before implementation, inventory its actual project, media, timeline, version, render and export interfaces and existing ownership rules. This outline does not claim that those interfaces are already exposed to canvases or promise unsupported editor operations.

Use the shared picker and capability-card process to expose authorized production projects, with the user's own projects and only explicitly published/approved material available to other viewers. Attach stable project/version references and selected media to the project contract. Keep private prompts, unreleased footage, collaborator data and administrative metadata out of public inventories and publication snapshots. Related music, images and other assets retain their own access and rights checks.

Selection is read-only and does not start AI generation, rendering, export, playback or edits. Represent inspection, media retrieval, timeline editing, generation, rendering and export as separate capabilities where the editor supports them, with explicit read/write permissions, costs, side effects and existing project-approval rules. Use existing editor operations rather than creating a parallel editor. No mandatory production panel, video widget or fixed canvas layout is introduced.

Future design must resolve supported reference granularity (whole project, sequence, clip or rendered asset), revision/live-reference behavior, large-media delivery, render-job recovery and Stop semantics, collaborator authorization, and share/remix rights. Acceptance must cover current-account isolation, withdrawn assets, project-version binding, selection without execution/spend, authorized job accounting and compatibility with the existing editor. Keep these details unresolved until the editor inventory supplies evidence.

### Playlists selection

`/playlists` opens a searchable multi-select picker. Show the current account's own playlists and any explicitly published artist playlists, with clear ownership/visibility labels and track counts. Never expose another account's private playlists. Selecting attaches stable playlist references and their authorized ordered track membership to the project contract; it does not start playback, a build or an AI charge.

Resolve every song through the canonical track ID and existing authorized MP3/audio interfaces. Preserve playlist order and repeated entries where supported; show missing, withdrawn or unavailable tracks explicitly. Load the complete selected membership through pagination as needed, with explicit context limits instead of silent sampling. Revalidate ownership, publication status and track availability at build and runtime. Distinguish the approved membership snapshot from later live playlist changes so revisions do not silently change requirements.

Playlists are collections of independent song nodes, not mandatory player widgets. The agent authors the requested presentation and interactions and may synchronize playback with the main player through the existing host interfaces. Selecting a playlist does not authorize changes to its saved membership; playlist writes require separately declared permissions and applicable approval. Retain selections across revisions unless changed or explicitly cleared. Account switching, deletion and permission revocation invalidate stale private results.

Acceptance includes multi-selection/clearing, complete ordered membership, duplicate handling, public/private isolation, canonical playback and main-player synchronization, missing-track diagnostics, selection without execution/spend, and no injected player UI. The picker attaches `{playlist:own-ID@VERSION}` or `{playlist:artist-ID@VERSION}` references. `{playlists-clear}` clears inherited playlists. The `playlists` inventory is served by the existing canvas resources endpoint.

### Stories selection

Add `/stories` using the existing resource-picker interaction: search, inspect a concise preview, select multiple published stories and attach their stable references to the composer. Include title, published narrative and available metadata; related song/release/media references retain their own authorization and rights checks. Do not expose drafts, administrative notes or unpublished media through the public inventory. Any future owner-only story access requires a distinct declared scope.

Selected stories are inventory for authored experiences, not instructions or mandatory story cards, timelines or widgets. The agent may use them as narrative context or render their contents as requested. Preserve canonical story identity and provenance; distinguish quoted source material from generated interpretation. Revision contracts retain story selections unless explicitly changed or cleared. Reopening uses current authorized availability; immutable publication snapshots must follow the established public-data, rights and withdrawal policy rather than copying private story contents into source.

Acceptance: searchable multi-selection and explicit clearing; selection without build/spend; complete selected inventories with explicit pagination/context limits instead of silent three-item sampling; guest/account isolation; published-only story visibility; related-resource authorization; withdrawn story handling; prompt fidelity without inserted widgets; current-viewer data boundaries; and compatibility with existing image, music, canvas, merch and ticket selections. Final token syntax and endpoint additions are implementation decisions and are not existing API contracts.

## Account token meter and cross-experience analytics

Add a compact token usage meter to the public user dropdown. Show the signed-in account's remaining Active Tokens as an exact count and percentage, with a labeled progress indicator and accessible text. Use authoritative existing balance/reservation accounting; show spendable availability accurately when work has reserved tokens. Refresh after confirmed usage, cancellation/settlement, balance changes and account switching without duplicate charges or fabricated values. Keep account balances private; unavailable/loading states must not display a false zero.

Define the percentage denominator from the actual entitlement/accounting model before implementation. Label what 100% means (for example, the current allowance); handle top-ups, carryover and unlimited accounts explicitly rather than inventing a universal monthly reset or misleading percentage. Where the balance has no meaningful allowance denominator, show the exact remaining count and an honest percentage-unavailable state until a valid basis exists. Display actual remaining values; a bounded visual bar must not conceal balances above the allowance. Separate any additional provider-specific limits from Active Tokens.

Song plays, listening time and completed listens across custom canvases/apps must feed the existing canonical listening totals. Attribute activity to canvas/app identity and version, current account or authorized privacy-respecting session, canonical track ID and playback method. Main-player synchronization and independently processed authorized MP3 playback must share deduplication/session rules; source retrieval or a generated-code play assertion is not evidence of actual listening. Use host-observed playback where available and explicitly classify unsupported/unverified playback rather than pretending arbitrary sandbox audio can be measured authoritatively. Reuse established valid-play/completion rules; do not create competing totals. Canvas engagement and technical performance metrics remain separately named and do not inflate song totals.

Acceptance includes current-account isolation, exact count/percentage consistency, documented denominator and unlimited/top-up states, reserved versus available balances, late settlement/account-switch races, and mobile dropdown readability. Analytics checks cover main-player/canvas double reporting, repeated events, pause/resume/seek, song changes, independent audio, cancellation, multiple tabs and privacy retention/export/deletion. Apply anti-abuse and idempotency rules without treating telemetry as permission for unrelated personal-data access.

## Build sections and acceptance criteria

| Section | Planned behavior | Acceptance evidence |
| --- | --- | --- |
| Capability contracts and host enforcement | Maintain a versioned registry of tools, inputs, outputs, read/write scopes, account boundaries, side effects and costs. The host authorizes every operation for the current viewer. Sensitive actions use trusted host controls and existing approval preferences. | Guest and distinct-account tests; denied cross-account and undeclared operations; host actions cannot be authorized by authored code or an AI verdict. |
| Administrator permission controls | Add an Admin Permissions section with a toggle for every registered permission, clear scope/account/consent information, explicit saving, policy versions and change history. Keep permission availability distinct from library enablement and viewer consent. | Server-side enforcement survives direct calls, stale cards and existing grants; disabling access blocks subsequent operations; admin role/CSRF, revocation and compatibility tests pass; core isolation protections remain enforced. |
| Canvas capability summary and remembered permissions | Show What this canvas uses on catalog entries, identifying tools and personal-data access. Remember the viewer's grants across visits and trusted revisions of the same canvas. Request renewed consent only when access expands; provide account controls to inspect and revoke grants. | Repeat visits and equal or narrower revision scopes reuse valid grants; expanded data/action scopes require consent before access; account changes, revocation and independent remixes cannot inherit another permission context. |
| Approved scripts and libraries | Maintain an administrator-controlled registry of reviewed, versioned bundles and their complete dependency graphs. Block unapproved scripts and remote script loading by default. Deliver selected verified libraries through Stonefellow while retaining sandbox and host permission boundaries. | Exact bundle and dependency hashes enter the canvas lockfile and audit key; undeclared imports/CDN loads and substitutions fail; approved library use cannot grant personal-data or action permissions; updates, revocation and mobile compatibility are tested. |
| Media palettes and adaptive host colors | Plan a Color Thief integration with the main media library: extract and retain image palette metadata, then use the active artwork's palette to coordinate the orb, player, chat footer and related host controls through the existing theme system. | Owned/public image access stays scoped; metadata is keyed to exact image bytes; replacements invalidate stale palettes; explicit color choices, readable contrast, fallback, mobile performance and image/source transitions are verified without new AI calls. |
| Weather data source | Expose current conditions and hourly/daily forecasts through a general host-mediated tool available to the agent and authored canvas code. Support selected public locations, show/venue locations and the viewer's authorized location preferences. Let the approved brief determine presentation and behavior. | Validated locations, units, time zones and typed fields; fresh/stale/unavailable responses; cache and provider limits; separate device-location consent; no publisher-private location in shared scenes; no direct third-party script/API access from canvas code. |
| NASA data sources | Add NASA APIs to the planned public-data inventory for agent and canvas use, covering reviewed astronomy/space datasets and image/video discovery. The approved project determines how these resources are combined and presented. | Endpoint availability and quotas verified; server-managed credentials and caching; source dates, provenance and credits retained; per-asset reuse rights checked; retrieved content passes existing media and security boundaries. |
| Additional public data sources | Add OpenStreetMap, NOAA Space Weather, USGS earthquakes, NOAA Tides & Currents and Wikidata as general data tools the agent can combine according to the project. | Each source has a verified endpoint/schema, coverage, commercial-use terms, attribution, quotas, freshness and supported fields; location privacy, caching, upstream access and failure handling follow the shared host controls. |
| Project-specific library selection loop | Reason from the user's intended experience, required capabilities and deployment constraints; compare native browser APIs and approved library combinations; choose the best fit, record the decision, validate the result and learn from measured outcomes. No library is preselected or preferred by default. | Different project needs can produce different choices, including no external library or multiple complementary libraries; choices have brief evidence-backed reasons; the agent preserves the requested experience and approved access scope; failed comparisons and compatibility results inform later choices. |
| Static security analysis | Parse supported HTML, CSS and JavaScript; examine dynamic execution, obfuscation, transmission/navigation attempts, embedded credentials, host API use and relevant data flows. | Positive and negative fixtures, code locations, rule identifiers, confidence and evidence; uncertain patterns are distinguished from confirmed violations. |
| Fraud and privacy assessment | Examine credential collection, impersonation, misleading destinations or purchase flows, hidden tracking, unnecessary collection, and literal personal data embedded in source. Interpret findings in the context of the requested experience. | Legitimate creative interfaces remain valid; suspicious behavior receives reviewable evidence; private values and credentials are redacted before optional model review and report delivery. |
| Controlled runtime evidence | Exercise guest and synthetic signed-in contexts with no real credentials, purchases or account mutations. Record prohibited operations, resource exhaustion, errors and capabilities actually exercised. | Isolation, cleanup and adversarial tests; unavailable or unfinished checks remain explicit; a controlled preview is never described as complete live-device verification. |
| Prompt fidelity and functional testing | Derive testable expectations from the approved brief. Check required interactions, real resource bindings and unintended content or controls. | Behavior checks use actual observations; invented catalog facts, missing features, displayed prompts and unrequested UI can be identified without imposing a preset design. |
| Visual and device review | Inspect rendered phone and desktop views for overlap, readability, broken images, control access and alignment with the user's design intent. | Screenshots and interaction evidence from identified viewport/device environments; accessibility and performance findings remain separate from aesthetic preference. |
| Regression-aware editing and Repair | Apply the requested change while carrying forward the other approved requirements. Test the revision and keep the working version available. | Existing interactions, assets, resource scopes and attribution survive unrelated edits; intended removals follow the user's changed requirements; rollback and same-scope repair are verified. |
| Reproducible debugging and dependency impact | Create a private, redacted reproduction bundle with code/tool versions, resource identities, relevant observations and a minimal interaction sequence. Track which canvases depend on changed capabilities. | A repair worker can reproduce representative failures with synthetic data; SDK/API changes trigger affected compatibility checks; prior private account payloads are excluded. |
| Audit decisions and creator review | Show severity, confidence, affected code, evidence, suggested fixes, requested capabilities, coverage and unresolved checks. Preserve audit history and reviewer reasons. Creators can submit corrections or explain a suspected false positive. | Exact-snapshot binding, role/CSRF checks, actionable feedback, history and stale-review tests; confirmed critical violations prevent publication. |
| Publication response and recovery | Support reports, administrator quarantine, unpublishing, appropriate notifications, rescanning, and administrator-approved rule rollback with a matching re-audit. Assess already-owned remixes separately from the public source. | Revocation, existing-copy ownership, rollback, affected-version lookup and safe diagnostic tests; historical reports cannot restore approval or republish withdrawn content. |
| Learning and independent challenge | Analyze confirmed findings, missed threats, false positives, runtime incidents, review outcomes and capability changes. Form threat hypotheses, propose checks and minimal test cases, and have a separate reviewer challenge their validity. | Reproduced evidence, synthetic examples, reviewer judgment and hypotheses remain distinct. A submission cannot instruct the learner or weaken enforcement. |
| Rule validation and release | Compare proposed rules against known malicious and legitimate code, evaluate them in shadow mode, measure outcomes, and present the evidence for administrator approval. Version released rules and identify affected publications. | No live policy change before approval; candidate regressions block release; rollback and targeted rescanning work; AI cannot override deterministic host restrictions or publish decisions. |
| Operations, costs and privacy lifecycle | Deduplicate equivalent audits, manage the queue, configure submission limits and AI-review budgets, record usage separately from creator build tokens, and integrate export/deletion/retention. | Idempotency, replay, ownership, atomic usage, budget, privacy-epoch, nested transaction and post-commit cleanup tests. |

After each Repair, functional, visual and reproduction evidence must match the latest candidate's code and asset hashes, renderer/SDK versions, fixtures and environment. Reject stale screenshots, traces and reports as evidence for the replacement candidate.

## Cognitive improvement loop

1. Observe submitted code, audit evidence, runtime incidents, reviewer decisions, false positives and changes to available capabilities.
2. Classify the root cause: submitted behavior, host/API defect, insufficient test coverage, or an inaccurate audit rule. Fix the responsible layer.
3. Develop threat hypotheses and synthetic reproduction cases. Anticipated threats remain labeled as hypotheses until supported.
4. Propose updated checks, audit guidance and regression tests. Treat all submitted content as untrusted evidence.
5. Have an independent reviewer reproduce findings and challenge the proposed changes.
6. Compare current and candidate rules against the retained corpus and in shadow evaluation. Measure confirmed detections, misses, false positives, coverage, latency and cost.
7. Present the evidence, impact and rollback plan for administrator approval. Release a versioned policy and rescan affected publications.
8. Feed observed outcomes back into the loop. Revalidate stale hypotheses and retain generalized patterns under the privacy policy.

The learning resides in versioned threat knowledge, audit rules, tools and tests. An administrator's decision is recorded separately from reproducible technical evidence. Raw user submissions and private account data do not become unrestricted shared learning material.

## Decision and authority rules

- Bind each audit to the exact submission hash, asset hashes, relevant capability versions and analyzer/rules version. A changed submission needs a matching fresh audit.
- Confirmed critical policy violations block approval. Contextual concerns receive human review. Missing, failed or incomplete required checks never become an automated pass.
- Where policy permits a manual decision, record it explicitly with its evidence and reason. It cannot override host access controls or disguise an incomplete automated assessment.
- AI may investigate, explain, prioritize, propose improvements and prepare repair work. It cannot grant capabilities, alter live audit policy or publish a submission by itself.
- Preserve the existing per-project approval preference. Publication and security-policy approval remain administrator decisions.
- Keep security, privacy, fraud signals, prompt fidelity, functionality, visual/mobile behavior, performance and operational readiness separately assessable. Each result identifies coverage and untested areas.
- Runtime/preview execution protection and host-request controls are separate from generation. Do not introduce fixed model-stream or code-build deadlines, or a fixed automatic repair-count limit.

## Implementation constraints to resolve before execution

Authoritative publication checks must run under trusted host control. Client-reported syntax trees, screenshots, runtime results or pass flags are untrusted evidence unless produced and bound by an authorized verification path.

Choose a supported source-analysis implementation that fits the existing PHP/shared-host deployment, or define an independently secured analysis runner. Do not assume HostGator provides a Node process or headless browser. Keep pure static analysis, guarded administrator preview, controlled runtime verification and real-device testing explicitly identified.

Optional AI security review uses an administrator-controlled model and budget. Deterministic analysis and redaction precede any provider request that might otherwise expose embedded credentials. Store the evidence needed to explain a finding with minimal sensitive content.

## Delivery sequence and scoring

Implementation has resumed. Use this dependency order; each phase must preserve the existing working flows before the next expands them.

1. **Inventory and contracts:** Map actual existing picker, account-data, knowledge and production-editor interfaces. Establish the shared project/candidate contract, stable resource relationships, capability cards, exact dependency registry, threat model, audit schema and trusted execution boundaries. Record missing interfaces rather than assuming they exist.
2. **Permission foundation:** Implement administrator permission controls, current-viewer host enforcement, What this canvas uses, remembered consent and revocation. Verify guest, owner, distinct-account and independent-remix boundaries before exposing additional private data.
3. **Resource discovery:** Reuse existing images/music/merch/tickets contracts; unify owned/public canvas discovery; add playlists, stories, lyrics, knowledge and per-category `/me` access. Add the shared searchable menu and resource inspector. Keep `/production` gated to interfaces confirmed by the inventory.
4. **Data and dependencies:** Add `/data` and reviewed provider adapters, beginning with NWS for supported U.S. locations; enable other sources and exact reviewed library versions incrementally. Integrate the project-specific selection loop and media palette/adaptive host colors without default scene layouts or mandatory libraries.
5. **Audit and verification:** Implement deterministic static checks, privacy/fraud assessment, redaction and the exact-snapshot publication gate. Extend trusted runtime evidence, functional/visual review, regression-aware edits and reproduction bundles. Resolve hosting/verification-runner requirements before relying on their evidence.
6. **Review and learning:** Connect administrator/creator findings, reporting/quarantine, cost/privacy lifecycle, dependency impact and recovery. Add threat knowledge, independent challenge, shadow evaluation, versioned policy approval, rollback and targeted rescanning. AI proposals cannot change live enforcement themselves.
7. **Release acceptance:** Review and repair each section against its agreed criteria; run appropriate regression, database, HTTP and browser checks, plus explicit physical-device/hosting checks where available. Then open the PR, merge after required checks pass, and produce a deploy ZIP from merged source. Report incomplete environments honestly; do not claim release readiness from documentation alone.

A section reaches 10/10 only against its stated, evidenced acceptance criteria, with no unresolved material implementation findings. This is a build-readiness assessment, not a guarantee of detecting every future threat. Report local checks, live hosting/database verification and physical-device coverage distinctly.

## Canvas capability summary and remembered permissions

This is approved build scope. Show a concise **What this canvas uses** summary on catalog entries, identifying the tools and personal-data access declared by the server-validated capability contract.

Remember grants for the signed-in viewer and the server-owned identity/version lineage of that canvas. Repeat visits and revisions with the same or narrower access reuse valid grants. New datasets, broader data access or additional action permissions require renewed consent before those capabilities become available. Unchanged granted access need not be approved again.

Let the viewer inspect and revoke grants through account controls. Account changes and independent remixes use their own permission context. A capability grant remains subject to host authorization and the existing purchase, account-change and token-spending approval rules.

The [administrator permission controls](canvas-capability-card-format.md) provide a toggle per registered permission in a dedicated admin section. Admin enablement determines availability; account authorization, user consent and any browser permission are still enforced independently. Saved administrator changes are versioned and audited, and disabled permissions cannot be bypassed through remembered grants or authored code.

## Weather data source

This is approved outline scope; implementation remains paused. Add weather as a reusable data capability for the agent and canvas code, with a documented schema and representative test payloads. The agent uses it according to the approved experience rather than receiving a fixed weather widget or mandatory visual effect.

- Plan a trusted PHP provider adapter behind a stable host endpoint, provisionally `api/weather.php`. Keep provider credentials server-side and restrict upstream requests to reviewed destinations and parameters. A canvas requests the weather tool through the existing host/data interface, preserving the sandbox's external-network restrictions.
- Support current conditions and hourly/daily forecasts, including available temperature, apparent temperature, precipitation, wind, humidity, condition codes and day/night/sunrise/sunset fields. Normalize provider values, units, valid times, location/time zone, source attribution, fetch time and freshness state. Distinguish modeled conditions from station observations where relevant.
- Accept an explicitly selected city or public location, an authorized show/venue location, or an account-scoped location preference. Device location is an optional, separately declared and consented capability. Use only the precision needed for the requested experience; preserve browser permission behavior and the user's host-level grant/revocation controls.
- Shared experiences resolve a viewer's private location context for that viewer. Keep the creator's saved personal location out of public snapshots and unrelated accounts. Explicit fixed public locations remain valid project resources.
- Cache and deduplicate compatible requests, with provider-aware freshness, attribution, quotas and configurable usage controls. Return explicit stale/unavailable states when the provider fails. Weather requests themselves make no LLM call; chat/build usage follows the existing token rules.
- Include weather and any personal-location access in What this canvas uses. Newly expanded location access requires renewed consent; revisiting an unchanged granted scope reuses the existing permission context.
- Test location and unit validation, daylight-saving/time-zone boundaries, valid-time versus fetch-time handling, provider errors, stale cache, rate limiting, account changes, denied/revoked device access and shared-canvas isolation. Use synthetic payloads for repeatable build, visual and security tests.

The user selected the National Weather Service as the planned provider for U.S. visitors and requires a free weather-service option. Use NWS for supported U.S. forecasts, observations and official alerts, following its identification, caching and usage rules. Resolve coverage from the visitor's explicitly selected or consented weather location, including a public show/venue location when requested; precise device location is optional. Preserve the current-viewer location and permission boundaries above.

For locations outside NWS coverage, MET Norway remains a candidate pending selection and review of its attribution, identification, caching and usage rules. Provider configuration remains administrator-managed. Open-Meteo can remain an optional provider if an appropriate commercial plan is selected; its free hosted tier does not meet Stonefellow's commercial-use requirement. NWS outages or unsupported fields return explicit stale/unavailable states; a paid provider is not silently substituted. Additional data such as air quality or alerts is declared only when the enabled provider actually supports it.

Provider references:

- MET Norway API and terms: https://api.met.no/doc/FAQ and https://docs.api.met.no/doc/TermsOfService.html
- Open-Meteo forecast documentation: https://open-meteo.com/en/docs
- Open-Meteo plans and commercial-use terms: https://open-meteo.com/en/pricing and https://open-meteo.com/en/terms
- National Weather Service API: https://www.weather.gov/documentation/services-web-API

## NASA data sources

NASA APIs are approved for inclusion in the planned public-data inventory. This is an outline update; no provider connection, credential setup, asset import or code change is performed.

- Expose reviewed NASA endpoints through the general host data-tool registry, with documented schemas and sample payloads. Candidates include astronomy-picture metadata, image/video discovery and appropriate structured astronomy, Earth or space data; verify each endpoint's current availability before enabling it.
- Keep any required API credentials in administrator settings on the server. Apply endpoint-specific caching, deduplication, request validation and quota controls. Canvas code uses the trusted host interface rather than loading remote scripts or arbitrary upstream URLs.
- Retain source identifiers, titles, descriptions, dates, attribution and rights information. Check reuse permission for each media asset; inclusion in NASA discovery results is not sufficient evidence to approve its redistribution. Review logos, endorsement implications and third-party media separately where applicable.
- Route selected media through existing authorized media delivery/import and publication controls. Preserve credits and rights metadata when the resource enters the artist inventory, a private project or a public submission.
- Support explicit unavailable, stale and unsupported-resource responses, with repeatable fixtures for build/security tests. The agent chooses resources according to the user's project; NASA data does not impose a space theme or a preset canvas layout.

Reference: https://api.nasa.gov/ and the selected endpoints' official documentation and media-use policies.

## Additional public data sources

The user approved all five sources below for the planned inventory. Implementation remains paused. Data-source approval is separate from enabling any remote executable script, selecting a map renderer, or authorizing personal location access.

| Source | Planned data capability | Integration requirements |
| --- | --- | --- |
| OpenStreetMap | Geographic context, places and venue locations. | Record data-license and attribution obligations. Review map-tile delivery and geocoding as separate services with their own usage policies, capacity and caching rules. Public Nominatim is not an unrestricted autocomplete or bulk-geocoding service. Select a compatible renderer separately through the library-selection process. |
| NOAA Space Weather | Reviewed solar activity, solar-wind and geomagnetic-condition feeds. | Identify supported products, units, valid times, refresh cadence and provenance. Distinguish forecasts, observations and derived values. |
| USGS earthquakes | Recent earthquake feeds and supported historical event queries. | Use reviewed GeoJSON/event interfaces, stable event identifiers, units and update times. Handle revised event records, pagination, duplicate events and explicit unavailable states. |
| NOAA Tides & Currents | Tide predictions, water levels and coastal station observations. | Include station coverage, timezone, units, vertical datum and prediction/observation status. Unsupported inland locations and missing station products remain explicit. |
| Wikidata | Structured facts, identifiers and relationships. | Verify entity identity and retain provenance/retrieval time. Respect query-service limits and caching; treat retrieved labels/text as untrusted data. Keep artist-owned canonical records authoritative for Stonefellow catalog facts. |

Each adapter exposes a documented, typed contract and representative fixtures through the common host data-tool registry. Record source coverage, commercial-use terms, attribution, quotas, freshness and supported fields before enabling an endpoint. Keep secrets server-side, validate requests against reviewed destinations, cache/deduplicate responses, and return clear stale/unavailable/unsupported states. Shared canvases resolve viewer-authorized location contexts without bundling their creator's private location.

The agent chooses whether and how to combine these capabilities for the approved project. The data inventory supplies no mandatory map, graph, event panel or other fixed widget. Tests cover source outages, malformed responses, stale data, changed records, units/time zones, rate limits, canceled requests and account/location isolation. Maps are the next data-source planning priority because they can connect published venues, weather and selected locations.

Source references for implementation review:

- OpenStreetMap data: https://osmfoundation.org/wiki/FAQ
- OpenStreetMap tile policy: https://operations.osmfoundation.org/policies/tiles/
- Nominatim policy: https://operations.osmfoundation.org/policies/nominatim/
- NOAA Space Weather products: https://www.spaceweather.gov/products
- USGS developer resources: https://earthquake.usgs.gov/data/software/
- NOAA Tides & Currents services: https://tidesandcurrents.noaa.gov/web_services_info.html
- Wikidata introduction: https://www.wikidata.org/wiki/Help:Introduction

## Approved script and library registry

The registry and default blocking policy are approved build scope. Unapproved scripts and remote third-party script loading are blocked by default. Authored canvas code continues through the normal source audit and exact-hash execution path; the agent can choose approved tools and libraries to implement the requested experience.

Each entry records its package identity, exact version, original source, license and retained notices, bundle hashes, complete dependencies, supported browser/capability profile, verification evidence, lifecycle status, review decision and affected canvases. Review addons, loaders, workers, WASM components and default asset requests as part of the dependency graph. Different builds or plugins require their own recorded approval.

Stonefellow delivers reviewed bundles locally through the trusted renderer without giving the canvas same-origin access. The selected immutable dependency lock becomes part of the submission snapshot, audit identity and reproduction bundle. Preserve restrictive CSP, origin isolation and host permissions; use narrowly reviewed capability profiles when a library needs additional browser features. Avoid broad script allowlists, unrestricted runtime imports, arbitrary CDN loading, dynamic evaluation and silent version substitutions.

Known library bytes can be inventoried separately from authored code. A package name, copied code or claimed library version does not confer trust. Approval covers the exact bundle and its permitted integration; generated configuration, use of host APIs and personal-data access still receive their own checks.

An administrator can approve, enable, retire or revoke exact versions. Test each exact bundle with the production CSP and SDK, phone-browser capability fallbacks, existing canvases, remixes and reduced-motion behavior. Repeated mount, Stop and unmount must release WebGL contexts, animation frames, listeners, workers and audio nodes as applicable. Dependency or SDK drift invalidates matching audit evidence. Routine updates preserve pinned versions; confirmed security revocations identify affected publications for the established response process. The agent may suggest an unavailable library for administrator review without fetching or executing it.

Candidate inventory and recommendations, with no default selection:

The user approved adding Meyda, Tone.js, Konva, simplex-noise, chroma.js, Rapier, Phaser, Color Thief, OpenType.js, Paper.js, Interact.js, Cytoscape.js and VexFlow to the planned registry inventory. Color Thief additionally has an approved planned integration with the main media library and adaptive host colors. Family-level inclusion does not enable an unchecked version; exact bundles, dependencies and integrations still require the registry's security and compatibility evidence. This update changes the outline only.

| Library | Role | Planning status |
| --- | --- | --- |
| Three.js | General 3D scenes and graphics. | User-named candidate; verify the exact version, addons and sandbox/mobile integration before enabling a runtime entry. Select only when it best fits the project. |
| PixiJS | GPU-accelerated 2D graphics, sprites and interactive visuals. | Suggested candidate; version review and approval pending. |
| Anime.js | Animation, timelines and SVG/DOM motion. | Suggested candidate; version review and approval pending. |
| Matter.js | 2D rigid-body physics, collisions and constraints. | Suggested candidate; version review and approval pending. |
| D3 | Low-level data-driven graphics and custom visualization primitives. | Suggested candidate; version review and approval pending. |
| Meyda | Audio feature extraction for richer sound-responsive visuals. | User-approved inventory addition; exact-version review pending. |
| Tone.js | Interactive music, synthesis, effects and sequencing. | User-approved inventory addition; exact-version and host-audio integration review pending. |
| Konva | Interactive 2D objects, drawing, layering, dragging and transforms. | User-approved inventory addition; exact-version and touch/lifecycle review pending. |
| simplex-noise | Procedural noise primitives for textures, patterns and organic motion. | User-approved inventory addition; exact-version review pending. |
| chroma.js | Palette blending, color interpolation and data-to-color scales. | User-approved inventory addition; exact-version review pending. |
| Rapier | 2D/3D physics and simulation through direct JavaScript bindings. | User-approved inventory addition; exact-version, local WASM and CSP/mobile integration review pending. |
| Phaser | Browser-based 2D game framework with scenes, input, cameras and physics. | User-approved inventory addition; exact-version and complete game/audio lifecycle review pending. |
| Color Thief | Extract image palettes for media metadata and artwork-derived host colors. | User-approved planned media-library integration and inventory addition; exact-version and image/theme integration review pending. |
| OpenType.js | Read font outlines for custom letterforms and typography. | User-approved inventory addition; exact-version and approved-font parsing/delivery review pending. |
| Paper.js | Vector paths, curves and generative illustration primitives. | User-approved inventory addition; exact-version and direct-JavaScript/CSP compatibility review pending. |
| Interact.js | Dragging, resizing, snapping and multitouch interaction. | User-approved inventory addition; exact-version and accessible touch/scroll lifecycle review pending. |
| Cytoscape.js | Relationship graphs, network layouts and graph analysis. | User-approved inventory addition; exact-version, authorized-data and mobile-complexity review pending. |
| VexFlow | Render supplied music notation and guitar tablature. | User-approved inventory addition; exact-version and approved-font/asset delivery review pending. |

Native browser Canvas, SVG and Web Audio remain baseline capabilities subject to the existing host policies; using a library is optional. Existing internal dependencies, such as the Acorn parser, are tracked separately from creative libraries exposed to canvas code. The registry is not implemented by this planning update, and the checked source tree does not establish an installed Three.js bundle or version.

Audio libraries use authorized audio and the reviewed player integration, preserving user-gesture startup, playback synchronization, volume/ducking, Stop and resource cleanup. Microphone access remains a separately declared and authorized capability. Physics and procedural effects need measured phone-browser performance; seeded procedural inputs can support reproducible testing. Overlapping libraries remain alternatives or complementary tools selected by the project's needs.

Font and notation libraries use reviewed, locally delivered font assets. Paper.js uses explicit direct JavaScript APIs compatible with the preview guard; PaperScript compilation and `with`-based usage cannot bypass the existing execution restrictions. Interaction libraries must preserve intended page scrolling, keyboard access and touch behavior.

### Planned media palette and host theme integration

This is outline-only scope. No Color Thief bundle, extractor, metadata change or new runtime theme behavior is implemented by this document update.

- Add a reviewed local Color Thief build to the main media-library pipeline. Extract palettes from authorized image pixels and retain validated color metadata with the asset's byte hash, extractor version and extraction status.
- Reuse existing image, media-metadata and theme interfaces. Cache successful extraction per exact image version and invalidate the palette when its bytes change. Palette extraction should not trigger an AI request or deduct AI tokens.
- Resolve the current visual context from displayed canvas/background artwork, selected media, or track/release cover art. Define stable source precedence and honor explicit user/author palette choices; unrelated song changes should not cause flicker when the displayed background remains the palette source.
- Map extracted colors into the existing theme roles and shared CSS variables for the orb, ring navigation, player, chat footer, inputs, buttons, icons and readable text. Existing contrast handling and native color logic remain part of this mapping; chroma.js may be used if its reviewed capabilities benefit the implementation.
- Keep changes scoped to the host UI and its intended theme variables. Authored canvas styling continues to follow its own approved project requirements.
- Preserve image ownership and public-reference permissions. Private asset identifiers, URLs and palette records remain in their authorized metadata context; palette extraction does not grant new media access.
- Use the existing theme or last valid palette when an image is unavailable or extraction fails. Test transparent and low-information images, image replacement, revoked access, stale asynchronous results, account changes, reduced motion and phone-browser responsiveness.
- Treat this as the user's explicit platform-level Color Thief integration. The project-specific selector continues to choose canvas libraries according to each project's needs, with no universal preferred library.

### Project-specific library selection loop

This is planned scope, not an existing implemented selector. The coding model currently reasons about a project generally; the checked code has no dedicated approved-library registry, comparison process or outcome-driven library selection loop.

1. Interpret the user's intent and expected behavior. Identify requirements for rendering, animation, physics, visualization, audio and interaction without imposing a canvas template.
2. Identify relevant constraints: target browsers and devices, accessibility, performance, existing player/data bridges, approved permissions, CSP, lifecycle cleanup and dependency availability.
3. Compare native browser capabilities and suitable approved library versions or combinations using their recorded capability profiles and actual verification evidence. No library, including Three.js, is preferred by default. Fewer dependencies are a tradeoff to assess, not a substitute for satisfying the requested experience.
4. Choose the best-fit approach and record a concise engineering rationale, relevant tradeoffs, dependency lock and test expectations in the project contract. The result may use no external library, one library, or several complementary libraries.
5. Build within the existing project authorization and metering rules, then validate the actual result against the user's requirements, production sandbox, mobile/device profiles, performance and cleanup. Existing benchmark evidence can inform planning; new paid prototype work requires the normal authorized scope.
6. Reconsider the selection when measured failures, incompatibility or changed project requirements justify it. Preserve working dependency locks during unrelated edits. Any expanded data/action access or newly enabled library version follows the existing permission and administrator registry rules.
7. Feed consented, privacy-safe outcomes into versioned library capability and compatibility knowledge. Keep project context and evidence attached to outcomes so a successful choice for one type of experience does not become a universal default. A missing suitable approved dependency becomes a reviewable proposal rather than a remote script load or an unrequested replacement experience.

Library reference sources for implementation review:

- Three.js: https://threejs.org/ and https://github.com/mrdoob/three.js
- PixiJS: https://pixijs.com/8.x/guides/getting-started/intro
- Anime.js: https://animejs.com/documentation
- Matter.js: https://brm.io/matter-js/
- D3: https://d3js.org/what-is-d3
- Meyda: https://meyda.js.org/
- Tone.js: https://tonejs.github.io/
- Konva: https://konvajs.org/docs/
- simplex-noise: https://github.com/jwagner/simplex-noise.js/
- chroma.js: https://gka.github.io/chroma.js/
- Rapier: https://rapier.rs/docs/user_guides/javascript/getting_started_js/
- Phaser: https://www.phaser.io/oss/docs
- Color Thief: https://lokeshdhakar.com/projects/color-thief/
- OpenType.js: https://github.com/opentypejs/opentype.js
- Paper.js: https://paperjs.org/about/
- Interact.js: https://interactjs.io/docs/
- Cytoscape.js: https://js.cytoscape.org/
- VexFlow: https://github.com/vexflow/vexflow

## End-to-end walkthrough and release acceptance

This walkthrough is planning work. It introduces no live provider calls, permission grants or implementation changes. Its scenarios validate general processes; they do not become templates, required libraries or special prompt routes.

### Normal project journey

| Stage | User-visible outcome | Pass criterion |
| --- | --- | --- |
| Understand | A concise concept and any material assumptions appear in chat. | The saved contract matches the request, selected resources and expected behaviors; material ambiguity receives one focused question. |
| Select | The project identifies relevant tools, data and library choices with a short rationale. | Chosen exact versions are enabled and compatible; native-only and multi-library approaches are valid; no default library or unsolicited UI is introduced. |
| Authorize | The user sees What this canvas uses and the existing project approval flow. | Existing grants are reused; expanded access requires consent; the host enforces the current account and approved action/cost scopes. |
| Build | Coding progress and actual usage are visible in the existing workspace. | The active working canvas remains available; Stop remains actionable; reconnecting recovers confirmed work without duplicate execution or charges. |
| Verify | Functional, visual, security and privacy findings identify the candidate and coverage. | Every required result binds the current code/assets/dependencies; stale or incomplete evidence cannot become a pass; confirmed critical violations block publication. |
| Repair | A focused revision addresses findings while preserving other approved behavior. | The same contract and authorization boundaries hold; changed code receives fresh applicable checks; no-progress repairs produce an actionable status. |
| Apply | The checked candidate replaces the active experience after host acknowledgment. | Exact candidate, active operation, current account and Stop state are rechecked; previous versions remain recoverable. |
| Share | A submitted immutable version enters administrator review. | Only the matching reviewed snapshot publishes; public data and current-viewer private data stay separated; imported remixes preserve attribution and independent owned assets. |
| Learn | Results contribute evidence to proposed audit or capability improvements. | Hypotheses, reproduced findings and reviewer opinions remain distinct; independent challenge and administrator approval precede live policy changes. |

### Representative scenarios

| Scenario | Required evidence |
| --- | --- |
| Code-only creative experience | Works without a song, external source or mandatory library; fulfills the prompt; no unnecessary permission or provider request. |
| Experience using personal data | Guest/denied-grant behavior is explicit; authorized accounts receive only their own rows; logout and account switching destroy stale private contexts. |
| Experience combining public sources | Tool schemas, units, timestamps, attribution and unsupported fields remain clear; providers are reached only through the host; deterministic fixtures reproduce observations. |
| Artwork-derived host colors | Palette metadata matches the image bytes; orb/player/chat-footer roles remain readable; stale extraction cannot overwrite a newer image or account context. |
| Revision of a working project | Requested changes are traceable; other approved behavior, valid permissions and unrelated dependency locks persist; changed evidence is regenerated. |
| Publication and private remix | Approval binds an immutable snapshot; new viewers use their own grants/data; source withdrawal blocks new public access while existing independent copies retain their ownership boundaries. |

### Failure paths

| Failure | Expected handling | Recovery criterion |
| --- | --- | --- |
| Provider outage or quota response | Return a typed unavailable/rate-limited response or explicitly stale cached data with source times. | Preserve the working experience and contract; no invented values, secret leakage or silent paid-provider substitution. |
| Denied or revoked permission | Prevent the affected host operation and expose the contract's denied-access state. | Offer public/manual-location alternatives where the project supports them; request consent again only for genuinely required expanded access. |
| Connection interruption or reload | Recover the durable project and last confirmed stage by identity. | No duplicate model invocation, settlement or application; partial/unverified source remains non-executable; continuing paid work follows existing authorization. |
| Stop or account change during work | Cancel active work and reject late state updates. | No late mount, playback action, personal-data delivery or stale worker settlement under a new owner. |
| Broken or unavailable audio | Preserve a structured diagnostic describing selection/source/gesture/playback failure. | Repair can reproduce the failure through the canonical player and authorized MP3 interface; unrelated playback controls and project state remain usable. |
| Failed security audit | Explain confirmed violations and contextual concerns with redacted evidence. | Critical violations cannot publish; a corrected revision receives a new matching audit; AI cannot overwrite enforcement or prior evidence. |
| Visual or functional regression | Identify the broken requirement and exact candidate/environment. | Keep or restore the working version and verify the corrected revision against retained acceptance checks. |
| Revoked library or asset | Identify affected dependency locks and authorization failures. | Apply the established quarantine/review process where required; no arbitrary CDN fallback, silent version swap or restoration of withdrawn publication authority. |
| Unsupported browser capability | Report the unsupported feature and relevant device evidence. | Select an approved equivalent only if it still satisfies the project; otherwise present the limitation and a reviewable change proposal. |
| Stale asynchronous result | Compare owner, project, source image, candidate and request identities before applying. | An older response cannot replace newer code, palettes, location data, grants or playback selections. |
| Incorrect audit-rule update | Preserve evidence of the regression and affected rule version. | Administrator-approved rollback plus matching re-audit; historical reports do not republish withdrawn content or grant permissions. |

### Device, hosting and delivery rehearsal

Use the user's iPhone 11 with Safari and Chrome, plus desktop, as explicit target environments. Record browser/OS versions and viewport, touch/keyboard, audio startup, visibility transitions, memory/resource cleanup and supported graphics features. Browser emulation and physical-device results remain separately identified.

Before release, rehearse additive migrations, backup, upgrade and rollback against a staging copy of the existing deployment. Verify private histories, public snapshots, dependency locks, permissions, media metadata and canonical playback across the update. Keep runtime credentials, uploaded media and user storage out of deployment archives. HostGator execution limits and availability of external verification workers must be resolved by the implementation design rather than assumed.

### Planning decisions to settle before implementation

- Define required versus advisory checks and the exact administrator/manual-review authority for incomplete evidence.
- Select the trusted static/runtime verification environment compatible with the hosting plan; document what can be tested locally, remotely and on physical devices.
- Define stable project, candidate, dependency and permission identities, including server-owned revision lineage and independent remix boundaries.
- Approve the minimum documented capability card for every enabled tool/library: schema, supported versions, constraints, permissions, costs, lifecycle and representative fixtures.
- Specify provider cache/freshness rules and commercial/attribution requirements per enabled data source; NWS remains the selected provider for supported U.S. visitor locations.
- Define the user-facing recovery and status wording from actual durable states, with clear next actions and no fabricated completion percentages.
- Sequence implementation so each foundation can be reviewed and exercised before optional adapters/libraries are enabled. Completing the inventory does not require loading every dependency into every project.

## Related implementation references

- [Planned capability-card format](canvas-capability-card-format.md)
- [Existing canvas build workflow](canvas-build-workflow.md)
- [Existing Canvas Gallery](canvas-gallery.md)
- [Existing canvas project approvals](canvas-projects.md)
- [Existing resource selection](canvas-resources.md)
- [Existing music and data interfaces](canvas-music-data.md)

Deferred: community ratings and additional discovery features. Persistent experience state remains something authored code may choose to implement through general tools; this outline adds no mandatory memory UI or preset canvas widgets.
