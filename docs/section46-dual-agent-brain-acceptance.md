# Section 46 — Dual Agent Brain & Unified Canvas

**Release:** Stonefellow v1.3.45  
**Database schema target:** 1.3.35  
**Upgrade migration:** 2026-10-12-036, initializes customer-specific memories and separate administrator conversations. The existing installer/upgrade.php backup, checksum verification, locking, and safe migration path remain authoritative.

## Product model

Stonefellow retains ONE governed Agent tool registry and provider engine, but has TWO strictly different data and permission scopes:

**Customer Agent Brain:** one private workspace for each authenticated customer. It reuses the existing owner-scoped customer conversations, per-user decisions, public catalog, music player, account/library tools, and personal consent controls. A user may explicitly save up to 25 editable personal memories only while personalization consent is on. Memory retrieval is owner-specific; account privacy export, personalization reset, history clear, and closure cover those memories. The main public site canvas now includes My Agent Brain and My Agent Chat, with conversation history, switch, a genuinely new conversation, and the existing player retained.

**Admin Agent Brain:** each authorized administrator has distinct operational chat threads and messages, independent from customer conversations. It can review platform-level CRM/journey aggregates, registered tools, current proposals, approved outputs, and interrupted mission states. The Admin footer's typed and voice messages use that Admin Chat API. The canvas shows operational conversation and links to existing Campaign/Automation Builders; a slide-out right Brain shows current mission results, review-required state, tool inventory, and the canonical approved-action history.

The Admin operational ledger deliberately omits customer email, private chat excerpts, message text, details JSON, and even private customer conversation IDs. Administrators may not erase every customer's brain through Admin.

## Completed acceptance areas

| Section | Acceptance |
| --- | --- |
| 46A | Admin/customer operational privacy partition; remove private excerpts and global deletion |
| 46B | Explicit per-customer consent-controlled memories, owner-scoped Brain APIs and privacy export/clear |
| 46C | Independent per-admin durable conversation tables and non-executing operational plans |
| 46D | Shared server-authoritative tool registry with distinct fan/Admin contracts; aggregate-only optional LLM research |
| 46E | My Agent Brain canvas with owner-specific preferences, conversations, activity and deletion |
| 46F | Admin Chat/Brain read the same governed proposal ledger, with no second action executor |
| 46G | Private customer Chat canvas and safe resume/new-thread controls |
| 46H | Admin chat canvas, footer/voice dispatch, right-side Brain, accessible dismissal and mobile layout |
| 46I | SQLite cross-user and cross-admin isolation; inherited no-replay interrupted-work controls |
| 46J | Version/schema synchronization, data export correctness, historical regressions, merged-main CI, certified ZIP/SHA-256 |

## Authorization and permissions

All account-specific reads identify the owner using authenticated server-side session identity, never client-provided `user_id`. Writes use the existing CSRF-protected endpoints. Personal memories are explicit and unavailable for personalization when consent is off. Stored memory is **untrusted data** for model context, not instructions that can override permissions.

Admin Agent model output is never an execution authority. Campaign/journey proposals still use Section 44's exact SHA-256 approval, owner confirmation, durable idempotency, canonical draft saver and separate publication policy. Consent checks and no-replay interrupted lifecycle recovery from Section 45 remain enforced. Admin cannot request another customer's private Brain through Agent prompts.

## Operational caveats

Customer text/voice Chat and Admin text/voice Chat share respective existing speech/browser capabilities; audio capture requires a supported browser and permission. Current Admin research can use configured OpenAI/Claude models or grounded fallback responses. Actual mail, calendar, payment providers, physical media, and production database privileges must be smoke-tested after deployment. Automated release acceptance is not a claim of live integrations being certified.

If upgrading an installation running v1.3.44, back up the full site and database first, deploy the *complete* certified v1.3.45 archive, and run administrator `upgrade.php` to reach database target 1.3.35. Do not apply a standalone SQL fragment or overwrite the existing private data directory. Validate existing customer sessions, one owner's private memory, another user's isolation, Admin Mission Chat and drawer, and a disposable approved-but-unpublished journey draft in staging.

The v1.3.45 certified ZIP is built only from **merged main after a successful release gate**, with a SHA-256 checksum verified inside CI. Do not deploy a candidate branch archive as if it were certified.
