# Stonefellow v1.3.21 — Section 22 Fan Segments, Automations & Lifecycle Journeys Audit

## 22A — Canonical Fan Segments — 10/10 design
- Reuses the existing saved segment store.
- Dynamic CRM/consent/account/tag rules.
- Commerce product/order/spend rules.
- Campaign and CRM-event rules.
- Activity/inactivity windows.
- Audience preview and persistent membership ledger.

## 22B — Segment Transitions — 10/10 design
- Enter/exit state recorded per fan.
- Segment refresh is idempotent.
- Enter/exit can trigger published journeys.

## 22C — Lifecycle Automation Runtime — 10/10 design
- Manual, CRM-event, segment-enter, segment-exit and scheduled triggers.
- Durable dedupe key.
- Cooldown and maximum run budget.
- Per-fan run state and step index.

## 22D — Governed Journey Actions — 10/10 design
- Add/remove tag.
- Set CRM stage.
- Governed Agent message.
- Consent-aware email.
- Published campaign enrollment.
- Wait and exit.
- Automation-originated CRM activity cannot recursively trigger itself.

## 22E — Wait / Resume Scheduler — 10/10 design
- Persistent due_at.
- CLI scheduler.
- Due-wait resume.
- Scheduled journey execution.
- Segment refresh integrated into scheduler.

## 22F — Admin Journey Builder — 10/10 design
- Dynamic segment editor.
- Audience preview.
- Ordered step builder.
- Publish/pause.
- Explicit manual run.
- Governance boundary shown in UI.

## 22G — Campaign / Agent Integration — 10/10 design
- Campaign Audience node can use a saved segment.
- Campaign sends dynamically resolve the shared segment.
- Admin Agent routes lifecycle requests to the module.
- Fan Agent CRM context includes active segment/journey state.

## 22H — Audit & Explainability — 10/10 design
- Run ledger.
- Step event timeline.
- Failed/waiting/completed state.
- Admin audit + Agent Brain events.

## Regression gate — pending measured GitHub validation
- PHP syntax required.
- Public/Admin JavaScript syntax required.
- Lifecycle Admin JavaScript syntax required.
- Sections 1–21 inherited suites required.
- Section 22 executable segment/automation suite required.

PROVISIONAL SECTION 22 SCORE: **10/10 design / pending green release gate**
