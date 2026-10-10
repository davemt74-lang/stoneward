# Stonefellow v1.3.21 — Section 22: Fan Segments, Automations & Lifecycle Journeys

Stonefellow remains a **single-artist direct-to-fan platform**. Section 22 turns CRM, campaign and commerce history into reusable fan audiences and governed lifecycle automation.

## Dynamic fan segments

The existing campaign segment store is now the canonical Stonefellow audience layer.

Segments can match on:

- newsletter consent
- linked vs email-only fan identity
- proactive-Agent permission
- CRM lifecycle stage
- required tags and any-of tags
- purchase history
- minimum order count
- minimum merch spend
- specific merch products
- campaign participation
- CRM event types
- recent activity
- inactivity windows

Admin can preview matching fans before saving a segment. Segment membership is continuously evaluated and stored with enter/exit timestamps.

The same saved segment can be used by:

- Campaign Builder Audience nodes
- lifecycle automations
- manual lifecycle runs

## Lifecycle automations

Admin → **Segments + Automations** provides a lifecycle journey builder.

Triggers:

- Manual
- CRM event
- Segment entered
- Segment exited
- Scheduled interval

Actions:

- Add CRM tag
- Remove CRM tag
- Set CRM stage
- Governed Agent message
- Consent-aware marketing email
- Enroll in a published campaign
- Wait
- Exit

Published automations are standing Admin approval for the configured journey. Runtime policy still applies:

- marketing email requires newsletter opt-in
- Agent messages require a linked Stonefellow account and proactive-Agent permission
- campaign enrollment requires a published campaign
- dedupe keys prevent duplicate execution
- per-fan cooldowns and run budgets prevent repeated automation loops

## Waits and scheduler

Wait steps persist run state and a due time.

Run:

`php cron-automations.php`

The lifecycle scheduler:

- refreshes segment membership
- fires segment enter/exit transitions
- resumes due waits
- starts due scheduled automations

## CRM and Agent Brain

CRM events automatically feed lifecycle evaluation using the authoritative CRM event ID.

Automation-originated CRM events suppress recursive automation triggering.

Admin Agent Brain records:

- segment saves
- automation saves/publishing
- explicit manual lifecycle runs

The fan-facing Agent CRM context can see the fan's active segments and active/waiting lifecycle journeys, while normal consent/privacy controls remain intact.

## Run history

Every journey records:

- fan/contact
- trigger
- automation
- current step
- waiting due time
- completion/failure state
- step-level execution events

Admin can inspect a per-fan journey timeline.

## Database

Migration **2026-10-10-019** adds:

- `lifecycle_automations`
- `lifecycle_automation_runs`
- `lifecycle_automation_events`
- `lifecycle_segment_memberships`

Application: **1.3.21**  
Database schema target: **1.3.18**
