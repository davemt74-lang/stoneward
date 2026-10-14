# Section 82 — CRM, Marketing & Fan Automation Consent Certification

## Existing systems retained
Stonefellow already supports opt-in CRM contacts, newsletter subscriptions and unsubscribes, campaign segments and node-based flows, published marketing sends with explicit Admin confirmation, scheduled lifecycle automations, read-only Agent recommendations, and retry/uncertainty controls. Section 82 does not add a parallel CRM or an autonomous email sender.

## Changes
- CRM account/contact merges use the most restrictive historical consent. If either source has an unsubscribe timestamp, the merged contact stays unsubscribed; an ordinary account/purchase/support sync never resets it.
- A fresh, explicit newsletter signup may re-opt in, after which the CRM contact receives a new unsubscribe token through the established newsletter flow.
- CRM merge clears stale unsubscribe tokens when consent is revoked and carries the status/timestamp forward.
- Campaign email delivery rereads the authoritative CRM contact immediately before each send; missing/deleted contacts, changed identity/email, opt-out and unsubscribe are skipped. Skips are counted separately from provider failures in the existing campaign message run audit.
- Existing manual Admin send approval, automation consent checks, campaign analytics, and unsubscribe URL remain authoritative. No new external marketing service or ChatGPT connector is introduced.

## Acceptance scorecard (10/10 requires all)
1. Unsubscribe on a secondary CRM record blocks merged consent.
2. Unsubscribe on the owning account CRM record blocks merged consent.
3. Contacts without consent remain opted out.
4. Account-scoped contact merge retains the canonical contact ID and correct opt-out state.
5. Stale marketing unsubscribe token is invalidated on merged opt-out.
6. A fresh, explicit signup can consent again; silent sync cannot.
7. Campaign delivery rereads recipient consent immediately before the send.
8. Changed or deleted email/identity rejects the stale audience recipient.
9. Campaign sends distinguish skipped recipients from delivery failures; existing confirmation and auto-journey privacy checks stay in place.
10. Full PHP/JS regression suite and real MySQL checkout/inventory certification pass on exact PR head and then merged main.

## Deployment boundaries
This release does not automatically launch marketing campaigns or send emails. It does not claim a user has opted in when CRM records disagree. It also does not make uncertain provider deliveries safe to replay: existing outbound send recovery policies continue to apply. External email provider delivery is not certified by automated tests.
