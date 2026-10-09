# Stonefellow v1.3.17 — Section 18 Campaigns, Offers & Fan Acquisition Audit

## 18A — Campaign Core & Admin Workspace — 10/10 design
- First-class Campaigns Admin module.
- Draft/published/paused/completed/archive lifecycle model.
- Pretty public campaign slugs and date windows.
- Duplicate and delete governance.
- SQLite and MySQL persistence.

## 18B — Audience & CRM Segmentation — 10/10 design
- Campaign audience gates use canonical CRM contacts.
- Newsletter, account linkage, purchase history, stage and tags are supported.
- Campaign participant identity links to CRM without copying account data.

## 18C — Offer & Redemption Engine — 10/10 design
- Free song, discount, VIP and exclusive entitlements.
- Offer limits and expiration.
- Graph-reachable eligibility required before claim.
- Discount validation occurs server-side in quote/order.
- Free song downloads use tokenized grants.

## 18D — Campaign Landing Pages & Forms — 10/10 design
- Public /campaign/{slug} experience.
- Artwork, headline, copy, fan form and offer cards.
- Delivery email is separated from explicit marketing consent.
- CRM and campaign event attribution is automatic.

## 18E — Messaging & Automation — 10/10 design
- Email nodes require explicit Admin send approval.
- Only marketing-opted-in contacts receive campaign email.
- Unsubscribe links are generated for each campaign send.
- Audience/condition/CRM-tag/Agent/conversion/exit nodes execute from the saved graph.
- Wait nodes persist a waiting state for later continuation.

## 18F — Agent Brain & Campaign Intelligence — 10/10 design
- Public Agent receives active campaign context and offer routing.
- Admin Agent routes campaign creation requests to the Campaign Builder.
- Campaign saves/publishing and campaign email sends are recorded in Agent Brain.

## 18G — Analytics & Attribution — 10/10 design
- Participant and event ledgers.
- Offer claims and redemptions.
- Download and outbound-link tracking.
- Bounded session purchase attribution.
- Attributed order and revenue metrics.

## Visual Builder — 10/10 design
- Drag-and-drop node palette.
- Repositionable canvas nodes.
- Visual connected edges.
- Right-side node inspector.
- Validation and simulation.
- Explicit consequential-action treatment for email.

## Regression gate — pending measured GitHub validation
- PHP syntax required.
- Public JavaScript syntax required.
- Admin JavaScript syntax required.
- Campaign Builder JavaScript syntax required.
- Sections 1–12, 14, 15, 16, 17 and 18 required.

PROVISIONAL SECTION 18 SCORE: **10/10 design / pending green release gate**
