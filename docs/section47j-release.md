# Section 47J — v1.3.46 release certification

**Release:** Stonefellow v1.3.46 — unified Customer and Admin Agent skills, governed planning, personalized intelligence, evidence-backed Admin recommendations, opt-in proactive previews, logical specialist workstreams, Chat Canvas handoffs, explicit response feedback and safe recovery diagnostics.

**Migration:** `2026-10-12-037` creates exactly two additive persistent tables: `agent_mission_plans` and `agent_response_feedback`. It does not modify `users`, authentication, password hashes, customer conversation tables or existing Admin Agent history. Repeat execution is idempotent. Database upgrade must be preceded by the normal validated backup; test upgrades must demonstrate all existing user records survive.

**Authority:** The shared tool/skill registry remains descriptive. No arbitrary execution, automatic send or campaign publication; all consequential changes still use the original Admin/customer owner-controlled approval paths. The Customer and Admin Agent Brains remain physically and logically separate.

**Certification gates:** Section 47A–47J suites plus previous Section 42–46 regressions; PHP and both JavaScript syntax checks; fresh current-head CI on the PR; merge only when green; fresh post-merge CI on `main`; then the pinned `Stonefellow Certified Deploy ZIP` workflow must produce the deployment ZIP and SHA-256 file. The ZIP must be built from the exact successful `main` commit.

**Deployment:** Preserve the existing private data, keys and credentials. Upload the certified ZIP and run `upgrade.php` after backup. This release does not require or authorize an Admin password reset.

**Evidence limitation:** Automated SQLite upgrade and source-level MySQL schema checks do not substitute for a real MySQL production upgrade or live browser smoke test. Those must be verified during deployment.
