# Stonefellow v1.3.25 — Section 26: Release & Promotion Operating Calendar

Stonefellow remains a **single-artist direct-to-fan platform**. Section 26 gives the Admin and Agent one operating view across the release, campaign, show, ticket, membership and automation systems already built.

## One calendar, authoritative source dates

The Operating Calendar does **not** copy release dates, show dates, campaign windows or ticket windows into a second system.

It reads native dates directly from:

- Releases
- Shows + Live
- Campaigns
- Tickets + VIP
- Member / VIP content
- Scheduled lifecycle automations

Those events appear as **native source events**.

Stonefellow stores only the operating plans and milestones used to coordinate work around those dates.

## Launch plans

Admin → **Operating Calendar** can create:

- Single release plan
- Album release plan
- Show launch plan
- Campaign launch plan
- Custom plan

A plan has:

- name
- target date/time
- timezone
- status
- optional primary release/show
- notes
- milestones
- readiness score

Plan states:

- Draft
- Active
- Completed
- Archived

## Templates

Templates seed practical direct-to-fan milestones around one target date.

Single/album plans include work such as:

- master approval
- credits / metadata / ISRC
- artwork
- Campaign Builder journey
- merch / physical offers
- newsletter and fan segment
- VIP / presale / member access
- QA
- release day
- post-release follow-up
- analytics review

Show plans include ticket/VIP setup, campaign/presale, poster/media, fan communication, guest-list QA and post-show archive work.

Campaign plans include audience, landing page/media, messaging, entitlement/conversion QA, launch and conversion review.

## Dependencies and blockers

Each milestone can have:

- due date/time
- type
- owner/team label
- priority
- blocking flag
- dependency
- status
- notes

A downstream milestone cannot be marked complete until its dependency is complete.

Readiness calculates:

- percent complete
- overdue milestones
- dependency-blocked milestones
- open launch blockers
- next milestone
- ready/not-ready state

## Unified timeline

The Admin timeline merges native source dates and operating milestones.

Each row clearly identifies whether it came from:

- the native source system, or
- an operating plan

This prevents the calendar from silently changing a release, show, campaign or ticket date.

## Agent + Agent Brain

Admin Agent commands such as “show the release calendar,” “launch readiness,” or “open the launch plan” route to Operating Calendar.

Plan saves and milestone completions are audited. Significant plan operations feed Admin Agent Brain.

The core also exposes an internal Agent-ready summary with active plans, overdue work, blockers, seven-day workload and the next 30 days of calendar activity.

The Agent does not publish a release, campaign, ticket offer or merch product merely because a calendar milestone is completed.

## Database

Migration **2026-10-10-023** adds:

- `operating_plans`
- `operating_milestones`

Application: **1.3.25**  
Database schema target: **1.3.22**
