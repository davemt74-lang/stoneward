# Section 47H — Interactive Agent Chat Canvas handoffs

Customer Chat and Admin Chat now show durable mission steps and **user-initiated Open tool** controls. Both reuse the existing authenticated Brain read models and action flow rather than adding new endpoints or executing model-selected handlers.

## Customer
The Chat Canvas shows up to four recent private mission plans and their reviewed/pending steps. Catalog and track recommendation stages navigate to the published Music catalog. Playlist and favorites stages navigate to My Library, where the owner may choose to act through the existing UI. The explicit approval status remains displayed. Changing conversations or creating a new chat preserves its existing owner-scoped behavior.

## Admin
Admin conversation messages render bounded stage cards alongside aggregate evidence, with handoffs to existing CRM, Automation, Campaign and exact-review Journey builders. An opened editor is **not** an approval; the existing separate exact-hash proposal approval and execution ledger remains authoritative.

## Safety
All known stage IDs are routed through a fixed UI allowlist; arbitrary model text is not used as a URL, endpoint or JavaScript handler. Unrecognized intents show status without a button. The customer UI has no Admin route or CRM data. The Admin UI does not expose customer-private Agent conversations or personal memories. Writes, publishing, outreach and playback are not automatically executed.

## Acceptance
The 47H regression verifies role-specific views, both allowlists, safe escaping, strict handoff wiring, legacy conversation retention, explicit approval path and absence of new executors. The full release gate also checks PHP/JS syntax and every prior 47A–G regression.

No live browser deployment assertion is made from code-only CI.
