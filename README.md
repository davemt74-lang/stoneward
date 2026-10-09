# Stonefellow v1.3.17 — Section 18: Campaigns, Offers & Fan Acquisition

Stonefellow remains a **single-artist direct-to-fan platform**. Section 18 turns the Section 17 CRM into an operational campaign engine.

## Campaigns

Admin now has a first-class **Campaigns** workspace for building and managing:

- Newsletter acquisition campaigns
- Free song downloads
- Merch/order discounts
- VIP and ticket offers
- Exclusive/private content
- Release promotion
- Loyalty and win-back campaigns
- Custom fan-acquisition journeys

Every campaign has a public slug, lifecycle state, date window, landing-page content, CRM audience rules, workflow graph, participant history, offer claims and conversion analytics.

## Visual Campaign Builder

The Campaign Builder is a drag-and-drop node canvas.

Supported node types:

- Trigger
- Audience
- Condition
- Wait
- Email
- CRM Tag
- Agent Message
- Free Download
- Discount Offer
- VIP Offer
- Exclusive
- Redirect
- Conversion
- Exit

Nodes can be repositioned and connected visually. The builder includes a node inspector, graph validation and path simulation.

The saved graph is the campaign source of truth. Campaign entry executes audience and condition gates, CRM tagging, governed Agent messages, offer availability, conversion and exit behavior. Wait nodes persist a waiting state. Email nodes do **not** send automatically: every campaign email send requires explicit Admin approval.

## Audience and CRM

Campaign participation uses the existing Fan CRM identity layer.

Audience rules can target:

- newsletter subscribers
- linked Stonefellow accounts
- previous purchasers
- CRM lifecycle stages
- CRM tags

A campaign participant is connected back to the canonical fan contact. Entering a campaign, claiming an offer, downloading a track and converting all feed CRM history.

Providing an email to receive an offer does **not** imply newsletter consent. Marketing opt-in remains an explicit checkbox.

## Offers

Campaign offer nodes support:

- Free song download entitlements
- Percentage or fixed campaign discounts
- VIP/ticket links and access codes
- Exclusive/private links and access codes
- claim limits and expiration windows

Free song downloads use tokenized campaign entitlements and record the actual download.

Campaign discount codes are validated server-side in the cart quote and order flow.

## Campaign email

Email nodes define campaign newsletter/broadcast copy.

Campaign email sends:

- require a published campaign
- require explicit Admin confirmation
- target only fans with marketing opt-in
- generate a fresh unsubscribe link
- use the existing Stonefellow email lifecycle
- create a message-run audit
- are recorded in Admin Agent Brain

The existence of an Email node alone can never send a mass email.

## Public campaign pages

Published campaigns render at:

`/campaign/{slug}`

The public experience supports campaign artwork/copy, fan entry, explicit newsletter consent and eligible offers.

## Attribution and analytics

Stonefellow records:

- campaign views
- form starts
- campaign entry
- audience decisions
- offer availability
- offer claims
- downloads
- outbound offer clicks
- conversions
- campaign-attributed purchases
- discount redemptions
- attributed revenue

Campaign entry also creates a bounded browser attribution window so a later Stonefellow purchase can be credited to the campaign even when no discount code is used.

## Agent integration

The public Stonefellow Agent receives active-campaign context and can route fans to matching campaigns such as free downloads, discounts, VIP offers, exclusives and early-access promotions.

The Admin Agent routes campaign-building requests directly into the Campaign Builder.

Campaign authoring and campaign email sends are written into Admin Agent Brain.

## Fan Community

The Fan Community launch control introduced in v1.3.16 remains unchanged and defaults OFF. Campaigns, CRM and newsletter acquisition work independently of the public community.

## Database

Migration **2026-10-09-015** adds campaign, participant, event, entitlement, saved-segment and message-run storage for SQLite and MySQL.

Application: **1.3.17**  
Database schema target: **1.3.14**
