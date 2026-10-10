# Stonefellow v1.3.22 — Section 23: Membership & VIP Fan Experience

Stonefellow remains a **single-artist direct-to-fan platform**. Section 23 turns the existing subscription/package system into a real fan-membership and VIP experience without creating a second billing system.

## Package-backed membership tiers

Every existing Monthly Package can optionally act as a membership tier.

Structured tier configuration:

- membership enabled
- numeric tier rank
- member/VIP badge
- merch discount percentage
- early-access days
- VIP access
- priority presale
- member content
- exclusive downloads
- member-only offers

Stripe/local subscription status remains authoritative.

Active and trialing subscriptions receive membership benefits. A past-due subscription remains active only during its configured billing grace window.

## My Membership

Fans have a dedicated **Membership + VIP** experience from the chat + menu and normal navigation.

It shows:

- current membership tier
- member badge
- active benefit summary
- billing/access window
- member-exclusive content
- early-access items
- membership tier choices

My Account also displays the active membership badge and benefit summary.

## Member / VIP content

Admin → **Membership + VIP** manages gated content:

- posts
- audio
- video
- downloads
- announcements
- VIP offers

Each item can define:

- minimum membership tier/package
- minimum rank
- publication status
- public release/start time
- end time
- teaser
- full member content
- private/external destination
- featured/sort state
- Media Library attachments

A future start date becomes an early-access boundary: members whose tier grants enough early-access days can unlock it before the public start time.

VIP-offer content additionally requires the VIP benefit. Download content additionally requires the exclusive-download benefit.

## Secure member media

Media Library supports a new `member_content` relationship type.

Even when an attachment is marked Public inside the Media Library, `api/media.php` rechecks the current user's membership/tier entitlement before serving bytes for member-content links. This prevents sharing a direct media URL from bypassing membership gating.

Download permission remains separate from viewing permission.

## Member commerce benefit

Signed-in members can receive the merch discount configured on their active tier.

The server computes the benefit from authenticated subscription state; the client never supplies a tier or discount percentage.

Cart quotes expose:

- member discount
- campaign discount
- total discount

Member and campaign savings may coexist, but their combined value is capped at the subtotal.

## CRM + segmentation

Active members are synchronized to CRM stage `member`.

When membership ends, Stonefellow preserves relationship history:

- fans with purchase history fall back to `customer`
- otherwise they fall back to `fan`

CRM fan detail includes current membership state.

Dynamic fan segments can now target:

- active/inactive membership
- specific subscription package/tier IDs

This makes member-only campaigns and lifecycle journeys reusable through the existing segment engine.

## Agent

The Stonefellow Agent understands:

- the fan's current membership tier
- member badge
- active benefits
- VIP access
- early access
- merch savings
- member content/download eligibility

Membership/VIP questions route to the Membership experience.

Admin Agent Brain records membership-content publishing and package/member operations.

## Database

Migration **2026-10-10-020** adds:

- membership metadata columns to `subscription_packages`
- `membership_content`

Application: **1.3.22**  
Database schema target: **1.3.19**
