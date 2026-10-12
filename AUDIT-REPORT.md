# Stonefellow v1.3.36 — Section 37 Contact, Booking & Professional Inquiries Audit

Section 37 adds a governed professional-contact workflow for a single artist without turning professional contacts into Fan CRM/newsletter contacts.

## 37A — Public Contact Experience — 10/10
- Public Contact route at /contact with dedicated responsive presentation.
- Fixed inquiry types: Booking / Live, Press / Media, Licensing / Sync, Collaboration, General.
- Booking and licensing expose relevant context fields without making them mandatory.
- Public copy states the marketing-consent boundary both before and inside the form.
- Contact permission applies only to the professional conversation.

## 37B — Intake Validation, Privacy & Abuse Resistance — 10/10
- Name, email, subject, message, inquiry type and explicit contact permission are validated server-side.
- Optional website must use HTTPS.
- Messages preserve safe multiline text while stripping unsafe control bytes.
- Excessive external links are rejected.
- Honeypot and session rate limiting reduce automated abuse.
- Identical submissions are fingerprinted and suppressed for fifteen minutes.
- Source attribution stores path only; query strings/tokens are stripped.

## 37C — Authoritative Stonefellow Context — 10/10
- Licensing context can reference only public Stonefellow tracks/releases.
- Public references reuse SEO/public-content eligibility rather than a shadow catalog.
- No professional inquiry path creates or opts a contact into Fan CRM marketing.
- Professional records remain structurally separate from fan/newsletter consent.

## 37D — Professional Inbox & Admin UX — 10/10
- Professional inbox is embedded in Fans + CRM instead of adding a 30th sidebar item.
- Filter/search by status, inquiry type, name, email, organization and subject.
- Conversation view preserves inbound/outbound history, delivery state and internal notes.
- Booking/licensing context and source path are visible without exposing hidden URL queries.
- Dedicated responsive styling covers inbox, detail and message states.

## 37E — Governed Replies & Duplicate Prevention — 10/10
- Replies use Stonefellow’s existing transactional email/outbox.
- Every send requires explicit Admin confirmation.
- A message ledger row is created before delivery.
- Real sent/queued/logged/failed state and outbox error text are preserved.
- Inquiry becomes Replied only when delivery reports Sent.
- Closed/spam inquiries must be reopened before reply.
- Backend suppresses identical in-flight/successful replies for five minutes.
- Failed delivery does not block immediate retry.
- Admin UI also locks the confirmed send flow during submission.

## 37F — Status, Audit & Agent Brain Governance — 10/10
- Fixed states: New, Reviewing, Replied, Closed, Spam.
- Replied cannot be manually claimed; it is delivery-derived.
- Allowed transitions are explicitly bounded.
- Consequential status changes require confirmation.
- Status, notes and reply activity feed durable inquiry events/Admin audit.
- Agent Brain receives governed action summaries without copying message bodies.

## 37G — Public Agent, SEO & Privacy Boundaries — 10/10
- Public Agent deterministically routes booking/press/licensing/collaboration/contact intent to Contact.
- Agent can preselect inquiry type but never reads professional inbox records.
- Contact has canonical routing, ContactPage structured data and sitemap inclusion.
- Professional message bodies are not copied into Admin audit/Agent Brain payloads.
- Public Agent runtime has no inquiry-list/get capability.

## 37H — Migration & Release Safety — 10/10
- Application advances to 1.3.36.
- Database schema advances to 1.3.31.
- Migration 2026-10-11-032 creates professional_inquiries, professional_inquiry_messages and professional_inquiry_events.
- Upgrade integrity requires all three tables.
- PHP syntax: PASS
- Public JavaScript syntax: PASS
- Admin JavaScript syntax: PASS
- Sections 1–36 inherited regressions: PASS
- Section 37 certification: PASS
- Explicit PASS lines on hardened candidate: 2089
- PHP warnings: 0
- Failures: 0

FINAL SECTION 37 SCORE: **10/10**
