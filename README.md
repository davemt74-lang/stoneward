# Stonefellow v1.3.23 — Section 24: Ticketing, RSVP & VIP Guest Experiences

Stonefellow is a **single-artist direct-to-fan platform**. Section 24 turns Shows + Live, Membership + VIP, Campaigns, CRM and lifecycle automation into an operational guest-access system.

## Ticket and VIP offers

Admin now has a first-class **Tickets + VIP** workspace.

Offers are attached to the existing canonical Stonefellow show ID and support:

- RSVP
- Ticket
- VIP
- Meet & Greet
- Presale

Offer lifecycle:

- Draft
- Published
- Paused
- Closed
- Archived

Internal Stonefellow reservations are intentionally **free reservation/guest-list actions**. If an offer has a paid price, it must use **External ticket provider** fulfillment with a valid external checkout URL. Stonefellow does not pretend that an RSVP is a paid ticket transaction.

## Capacity and reservations

Internal reservations are account-backed and server-authoritative.

Stonefellow enforces:

- authenticated account
- CSRF protection
- unique retry/idempotency request key
- transactional capacity updates
- per-fan quantity limit
- unique human-readable confirmation code
- cancellation with capacity release
- reservation state history
- CRM identity linkage

The offer row is locked on MySQL before per-fan and capacity checks. Capacity increments use a conditional atomic update.

## Membership, VIP and presale access

Offers may require:

- any active membership
- a minimum membership tier/package
- minimum membership rank
- VIP benefit
- priority-presale benefit

If an offer has not reached its public start time, members with the **priority presale** benefit may gain access when the offer falls inside their membership tier’s configured early-access window.

The public offer payload explains why access is locked, such as:

- sign in required
- membership required
- higher tier required
- VIP required
- presale required
- not open yet
- sold out
- closed

## Guest list and check-in

Admin Ticketing includes:

- guest list
- quantity
- confirmation code
- reservation state
- check in
- confirmation-code check in
- cancellation
- CSV export

Check-in is idempotent. A checked-in reservation cannot be cancelled.

## CRM and lifecycle automation

Reservation actions write canonical CRM events:

- `ticket_reserved`
- `vip_reserved`
- `ticket_cancelled`
- `guest_checked_in`

Because CRM events already feed the Section 22 lifecycle engine, these events can immediately power segmentation and automation without a second workflow system.

## Fan experience

The public site adds **Tickets & VIP** to navigation and the chat `+` quick-action menu.

The public Tickets + VIP hub includes:

- active show offers
- access/lock reason
- capacity remaining
- internal RSVP / VIP reservations
- external official ticket links
- My Reservations
- confirmation codes
- cancellation

Show detail pages also load the current offers for that show.

## Agent integration

The Stonefellow Agent receives active offer context and the signed-in fan’s own reservation context.

It can answer questions about:

- upcoming ticket offers
- RSVP availability
- VIP access
- presales
- capacity
- the fan’s confirmation codes/reservations

The Agent may **not** reserve capacity or check a guest in. Those remain explicit fan/Admin actions.

The Admin Agent routes guest-list, ticket, RSVP and check-in requests to the Ticketing workspace.

## Database

Migration **2026-10-10-021** adds:

- `ticket_offers`
- `ticket_reservations`
- `ticket_events`

Application: **1.3.23**  
Database schema target: **1.3.20**
