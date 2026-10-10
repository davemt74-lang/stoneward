# Stonefellow v1.3.23 — Section 24 Ticketing, RSVP & VIP Guest Experiences Audit

## 24A — Show Offer Model — 10/10 design
- Canonical show IDs remain authoritative.
- RSVP, ticket, VIP, meet-and-greet and presale offers.
- Governed publication lifecycle.
- Internal vs external fulfillment is explicit.
- Paid offers require external checkout.

## 24B — Reservation & Capacity Engine — 10/10 design
- Authenticated account requirement.
- CSRF protection.
- Idempotent request keys.
- Unique confirmation codes.
- Transactional capacity enforcement.
- MySQL offer-row lock before per-fan/capacity decisions.
- Per-fan limits inside the reservation transaction.
- Cancellation releases capacity.

## 24C — Guest List & Check-In — 10/10 design
- Admin guest list.
- Confirmation-code lookup.
- Idempotent check-in.
- Checked-in reservations protected from cancellation.
- CSV export.

## 24D — Membership / VIP / Presale — 10/10 design
- Active membership gate.
- Package/rank gate.
- VIP benefit gate.
- Priority-presale gate.
- Existing early-access-day benefit determines presale window.

## 24E — Fan Experience — 10/10 design
- Tickets & VIP hub.
- My Reservations.
- Show-level offer cards.
- Capacity/access states.
- Public navigation and chat + quick action.
- External paid ticket links remain official provider links.

## 24F — Admin Ticketing — 10/10 design
- First-class Admin workspace.
- Offer editor.
- Guest list.
- Check-in/cancel.
- CSV export.
- Publish confirmation.
- Admin audit + Agent Brain.

## 24G — CRM / Lifecycle / Agent — 10/10 design
- Reserve/VIP/cancel/check-in CRM events.
- Existing lifecycle engine receives those events automatically.
- Public Agent sees active offers and user-owned reservations.
- Agent cannot autonomously reserve or check in.
- Admin Agent routes ticket/guest-list work to Ticketing.

## 24H — Analytics / Integrity — 10/10 design
- Offer, reservation, reserved-quantity, check-in and VIP summary metrics.
- Migration integrity requires all ticket tables.
- Full inherited release gate plus dedicated Section 24 policy suite.

## Regression gate — pending measured GitHub validation
- PHP syntax required.
- Public/Admin JavaScript syntax required.
- Ticketing Admin JavaScript required.
- Sections 1–23 inherited suites required.
- Section 24 executable policy suite required.

PROVISIONAL SECTION 24 SCORE: **10/10 design / pending green release gate**
