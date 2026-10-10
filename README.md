# Stonefellow v1.3.26 — Section 27: Business Intelligence & Growth Analytics

Stonefellow remains a **single-artist direct-to-fan platform**. Section 27 turns the existing listening dashboard into a unified **Performance Intelligence** workspace across the systems completed through Section 26.

## One read-only intelligence layer

Section 27 does not create a second reporting database and does not copy business state into shadow tables.

It reads the authoritative systems directly:

- Listening + conversion telemetry
- Fan CRM
- Campaigns + attribution
- Merch + products
- Orders
- Membership billing
- Tickets + VIP reservations
- Lifecycle automations
- Fulfillment, refunds + fan customer care

Database schema remains **1.3.22**.

## Recorded revenue

Performance Intelligence distinguishes real recorded money from engagement and attribution.

Recorded revenue is:

- Paid Stonefellow orders
- Paid membership invoices
- Less confirmed refunds

Campaign-attributed revenue is shown separately because it attributes existing orders; it is **not added again** to revenue.

Internal Stonefellow ticket reservations measure demand and check-in. External ticket URLs are not counted as revenue because Stonefellow does not own those external payment records.

## Fan growth

The unified dashboard shows:

- Total CRM contacts
- New contacts in the selected reporting window
- Total newsletter audience
- New newsletter opt-ins
- CRM lifecycle/stage mix
- Acquisition sources
- Weekly fan cohorts
- Customer/member progression

## Campaign performance

Campaign reporting now rolls up:

- Participants
- Conversions
- Conversion rate
- Attributed orders
- Attributed revenue

Campaign revenue and participants obey the selected 7/30/90/365-day reporting window.

## Merch + membership

Admin can see:

- Merch units sold
- Merch revenue
- Top products
- Low-stock warnings
- Active members
- Membership tier mix
- Paid membership invoice revenue
- Members scheduled to cancel

## Tickets + lifecycle automation

Performance Intelligence includes:

- Reserved ticket/VIP quantity
- Checked-in quantity
- Check-in rate
- Offer capacity/fill
- Lifecycle automation runs
- Completion rate
- Failed runs
- Waiting runs

Ticket activity obeys the selected reporting window.

## Customer care

The dashboard surfaces:

- Support cases opened
- Cases closed
- Current open cases
- High/urgent cases
- Refund requests
- Confirmed refunds
- Active shipments
- Delivered shipments

## Top supporters

Stonefellow ranks supporters by **recorded Stonefellow value** inside the reporting window:

- Paid orders
- Paid membership invoices

Ticket reservation quantity and membership tier are displayed as context but do not inflate revenue.

## What needs attention

The dashboard uses deterministic thresholds rather than generated guesses.

Current opportunity/warning checks include:

- elevated refund rate
- high-priority support cases
- failed lifecycle automations
- low-converting campaigns with meaningful participation
- near-capacity ticket/VIP offers
- memberships scheduled to cancel
- low-stock merch

If none are triggered, the dashboard reports a clear operating state.

## Admin Agent

The Admin Agent routes questions such as:

- “How is revenue doing?”
- “Show business performance.”
- “Who are the top supporters?”
- “How is campaign ROI?”
- “What needs attention?”
- “Show fan growth.”

to Performance Intelligence.

The API also produces one grounded **Admin Agent brief** from the exact metrics already computed for the dashboard. It does not recalculate a second set of numbers.

## Existing listening analytics remain

Section 27 does not remove the existing listening intelligence:

- starts
- completion
- skips
- repeat listening
- favorites
- playlists
- saved builds
- search/discovery
- notifications
- customer libraries
- per-track analytics
- per-user inspection

The business layer sits above those existing analytics.

Application: **1.3.26**  
Database schema target: **1.3.22**
