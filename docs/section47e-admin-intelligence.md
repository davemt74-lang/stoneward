# Section 47E — Admin operational intelligence

The Admin Agent now generates up to three transparent decision briefs from the existing server-authorized **aggregate** operational context. It does not inspect individual customer Agent Brains, private memory, chat transcripts, or contact records.

- Failed journey runs prompt manual review before retry.
- A CRM newsletter opt-in gap prompts a consent review, not unsolicited outreach.
- An opted-in audience with no active campaigns prompts a *reviewed draft proposal*, never a send/publish action.

Only allowlisted, finite, nonnegative integer aggregate counts are admitted. Missing metrics do not become inferred zeroes. Every insight includes a known evidence source, aggregate numbers, a safe next intent, and an exact-approval flag. The Admin Chat Canvas displays these in the existing conversation.

The existing Admin Agent action ledger, exact-hash approvals, and independent Customer Brain privacy remain authoritative. No new executor or model-selected permissions were added.

Certification: `tests/section47e-admin-intelligence.php` covers aggregate-only data, invalid metrics, missing-data restraint, approval boundaries, non-leakage and Canvas integration. Required by the full release gate.
