# Stonefellow G4M — Chat-First Production Calendar and Governed Deadline Extensions

**v1.3.135 · schema 1.3.68 · additive migration 059**

## One Agent Chat canvas, not multiple required dashboards

The existing **Admin Agent Chat canvas** is the primary user workspace. Asking “production calendar”, “crew deadlines”, “production schedule”, or “deadline extensions” returns a real production calendar *inside the chat message*, with chronological task cards, local-formatted due dates, overdue/availability signals and pending crew extension approvals.

An authorized editor can set a deadline and priority on a task card, or **approve/reject a contributor’s requested extension directly in the chat**, with a documented independent human review basis and an explicit confirmation. Completing the action refreshes the same chat conversation with a fresh calendar. These are existing authenticated Admin API calls, not invented model tool invocations. The slide-out Agent Brain uses the same private scheduling evidence and opens the calendar in Agent Chat instead of navigating to another dashboard.

The older Creative Director and Production Review pages remain optional for media-heavy specialist tools, not required for routine scheduling. This release does not claim every Studio/media-editing operation is already in chat. Media preview, legal clearances, publishing and advanced editors continue to use their governed existing surfaces until explicitly embedded.

## Contributor participation

Within the existing personal Creative Capture task card, assigned active crew can request a future extension of a real scheduled deadline, up to 90 days after the current deadline. They document why (25+ characters). The request is recorded but **does not change the due date**. The contributor can see the request’s eventual review status. Only the appropriate role-eligible active current assignee can request; revocation or reassignment immediately blocks further access.

## Independent review, concurrency and audit

Every proposal is tied to one real task, original assignee, original UTC deadline, original priority and requested UTC date. Identical pending requests are idempotent. A second conflicting pending request is rejected. The authorized editor reviewing is distinct from the requester and must record at least 25 characters of review evidence. Approval atomically updates the old date **only if the original date, priority, assignee and task status still match**, writes a unique reviewed decision and a task event, and appends to the existing Team Activity audit. Rejection records review without changing the schedule. An audit failure, stale task, stale date, duplicate approval or elapsed proposed deadline rolls back the transaction instead of changing anything.

**No** automatic assignment, outbound notification, model-driven schedule edit, AI media processing, render job, video publishing, or licensing changes. Calendar payloads stay inside the authorized Admin Agent request/action and the existing private history, never sent as external-provider context.

## Certification

`tests/section88d3g4m-chat-calendar.php` certifies exact-date optimistic locking, idempotence, permission isolation, creator/reviewer separation, approval and rejection, role revocation, duplicate suppression, expired date protection, human confirmation workflow contracts, and audit atomicity on top of the G4J–G4L real fixtures. `tests/section88d3g4m-release.php` certifies deployment identity, the new schema, Admin Agent routing and the existing private endpoints. All earlier release, FFmpeg, MySQL and deployment/package integrity gates remain required.

After backing up the existing installation, deploy the full ZIP and run `upgrade.php` to apply migration 059.
