# Section 47F — Proactive in-app research and recommendations

Section 47F surfaces existing intelligence proactively **when an authenticated Brain opens**, rather than introducing a duplicate notification scheduler, background crawler or browser permission.

**Customer** — a private published-catalog music preview is available only when all of the following are true: (1) personalization is enabled; (2) the customer's stored CRM `agent_auto_engage` flag is enabled; (3) the customer explicitly saved a Brain preference labeled `proactive suggestions` (or `proactive recommendations`) with the value `yes`, `on`, or `enabled`. The Brain UI explains how to save or forget this opt-in. Eligibility is checked on each request and the final candidate comes from the canonical published-catalog recommender.

**Admin** — the existing 47E aggregate-only decision brief is reused as a short research opportunity list with known internal data sources and exact approval flags. No private Customer conversations, contact records, or personal memories are imported.

**Safety** — these are passive in-app previews, not automatic messages, web research, sends, publications or CRM mutations. No extra executor is added. The existing Section 43 consent-controlled engagement pipeline remains independently governed.

**Certification** — real SQLite opt-in/opt-out tests, owner isolation, public suggestion status, no CRM mutation, evidence-only Admin results, and Customer/Admin Canvas integration are mandatory in the release gate.

This is internal evidence-based research. It is not an external-browser or internet research service; governed website research would require its own tool authorization and citations.
