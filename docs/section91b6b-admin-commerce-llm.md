# Section 91B6B — Unified Admin and Commerce Agent Tools

Admin chat can request a **structured LLM intent proposal** from the configured provider, then checks the chosen intent against the existing Admin skill and tool registry. The LLM never executes tools. The chosen intent maps to an installed screen or a read-only server function; exact-reviewed writes still use the pre-existing Admin proposal ledger.

## Data boundary

The orchestration prompt gets only a capped sequence of allowlisted generic words (such as `how many vip tickets` or `vinyl inventory status`). It does not contain the complete utterance, saved conversation history, names, email addresses, order IDs, private media, money details or tool credentials. Prompts marked private, containing order references, account secrets or exact protected edits bypass the external classifier. This conservative minimization reduces what the classifier can infer, which is preferable to exporting privileged Admin data without a separately audited opt-in.

## Actual reads and existing workspaces

- `ticketing_inventory_read`: Ticketing-core offer/reservation/check-in summary, button opens Tickets + VIP.
- `merch_inventory_read`: Commerce-core product, low-stock, unit and aggregate recorded gross merchandise sales summary, button opens Merch & Products.
- `vinyl_inventory_read`: Products classified as vinyl/record plus aggregate POD handoff statuses, button opens POD Handoffs. Not a full count of all custom vinyl builds or individual payments.
- `membership_inventory_read`: Membership-core active member and published-content summary, button opens Membership + VIP.
- Existing `commerce_report`, CRM aggregates and navigation remain available.

All reads execute only after the Admin Chat endpoint has authenticated the user. Responses do not disclose customer PII. The client receives bounded read evidence; no write request is generated. The fallback handles simple count/status questions when the LLM is unavailable.

## Unchanged safeguards

The LLM cannot invoke ticket check-in, reservation cancellation, published offers, stock adjustment, payments, order fulfillment, vinyl manufacturing, email sending or catalog publication. These remain in authenticated modules with existing confirmation. Explicit reviewed-action phrases (campaign, follow-up, metadata, knowledge, media and production) retain their original parsers and permissions without LLM reclassification. No schema migration.

Next: Section 91B6C can introduce schema-validated, entity-bound, multi-step commerce proposals with per-step human approval, source revision checks, and a live provider/voice/browser acceptance pass.
