# Stonefellow v1.3.44 — Section 45: Fan Journey Intelligence

**Product:** Stonefellow single-artist site. **Application:** 1.3.44. **Database schema:** 1.3.34, unchanged since v1.3.43.

## Acceptance coverage

| Section | Shipped capability | Acceptance |
| --- | --- | --- |
| 45A | Published-only lifecycle execution, atomic waiting-run claim and safe errors | tests/section45a-lifecycle-safety.php |
| 45B | Aggregate CRM, listening, subscription, purchasing, campaign and automation cohorts | tests/section45b-journey-metrics.php |
| 45C | Journey Intelligence tab within the existing Segments & Automations editor | tests/section45c-journey-builder.php |
| 45D | Recheck current marketing and personalization consent at outreach time | tests/section45d-outreach-consent.php |
| 45E | Correct lifecycle start timestamps and unique order attribution across campaigns | tests/section45e-attribution-growth.php |
| 45F | Evidence-backed advisory recommendations; no autonomous execution | tests/section45f-advisory-agent.php |
| 45G | New journey draft only, exact payload SHA-256 and typed approval, separate save using canonical automation saver | tests/section45g-reviewed-journeys.php |
| 45H | Agent recommendations and completed journey drafts visible in existing Admin and Agent Brain | tests/section45h-journey-dashboard.php |
| 45I | Two-hour stale-running quarantine, manual terminal reconciliation without replay | tests/section45i-journey-recovery.php |
| 45J | Full integrated regression, version synchronization and certified merged-main release | tests/section45j-release.php |

Each subsection's automated acceptance must be green on its exact merged source, as must all historical regression suites.

## Measurement and privacy boundaries

Independent contact-level cohorts are **not** a sequential listener-to-customer funnel. Anonymous listening is not attributable to a linked contact without prior authorization. Current opted-in subscriber counts and period-specific activity counts have different definitions.

Campaign conversion uses the same reporting-window entry and conversion cohort. Order attribution is first recorded qualifying event, with a cross-campaign unique order filter, not causal uplift. Attribution is limited to 20,000 qualifying events per window and reports when truncated. Lifecycle reporting uses started_at, the true schema field.

Journey advice uses aggregate evidence, minimum-sample guardrails and manual-review destinations. No individual fan names, email addresses, or private IDs are returned by the new metrics and advice endpoints.

## Existing system integration

Section 45 extends the original Stonefellow CRM, Campaign Builder, Segments & Automations editor, lifecycle scheduler, growth analytics, and the Section 44 reviewed action ledger. It does not create a second CRM or automation runtime.

The Agent may prepare and save only a **new unpublished draft** (ID=0, status=draft) through the canonical sf_automation_save method. The exact server-normalized payload is shown before typed APPROVE. No email, publication, purchase, or CRM mutation occurs as an Agent execution step. Existing administrator publication remains a separate, consequential action.

Consent is checked against the latest CRM record for each email and Agent message, including unsubscribe and personalization switches. A stale running journey is quarantined for manual review after two hours; it is not replayed. Acknowledgment does not undo already executed steps.

## Deployment and staging verification

1. Back up the live application and database before replacing files.
2. Deploy only the certified complete v1.3.44 ZIP. Run the existing administrator upgrade.php with its backup, lock and checksum preflight. Database target remains 1.3.34; upgrading from v1.3.43 requires no new schema migration.
3. In Admin → Segments & Automations → Journey Intelligence, check aggregate reports and measurement notes.
4. Prepare a disposable journey with Agent-reviewed draft, inspect its full approved payload, approve and separately save. Verify it remains unpublished with no outreach sent.
5. Review Agent Brain action history, mobile rendering, existing cron scheduler, MySQL/SQLite deployment, and live email/LLM services in staging.

Automated 10/10 acceptance refers to verified source gates, not a live-browser or real-email certification. Live integrations need their own deployment checks.
