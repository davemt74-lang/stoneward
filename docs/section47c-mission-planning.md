# Section 47C — Owner-scoped mission planning and governed read stages

Section 47C adds a bounded 2–4 step mission-plan model shared by the Customer and Admin Agents. Steps resolve exclusively through the 47B skill registry and the preexisting 46D action contracts; the model cannot choose arbitrary PHP handlers, write data, send campaigns or approve itself.

## Execution model
- Customer: requested playlist, favorites and published recommendations become ordered stages. Safe read stages await the actual customer discovery flow; personal writes remain visibly **approval_required**.
- Admin: campaign or journey requests start with CRM and journey aggregates. When those facts were already fetched from the authorized aggregate context, the two read stages become **read_completed**, with aggregate evidence only. A draft-save stage stays **approval_required** and is handed to the existing Section 44 exact-hash review/approval/save path. Publishing and sending are never in the executor.
- Both Brains surface ordered stages in their existing canvas/Brain read models. This is not an autonomous background task runner.

## Persistence and privacy
Owner and scope are checked on all reads and deletes. SQL is parameterized and plans use an opaque deterministic mission key for idempotency. The same conversation/request cannot create duplicate plans; a restart retrieves exactly the saved state. Plans store only registered intents, stage status, approved-safe aggregates and timestamps—not raw prompt content.

Customer privacy export now includes all owner-owned mission plans and clearing Agent history or closing the account deletes those plans. Account closure avoids CREATE TABLE inside its transaction, preventing a MySQL implicit commit. Admin cannot read customer plans through the Admin Agent.

## Certification
Functional SQLite tests cover duplicate prevention, owner/role isolation, restart retrieval, forged approval/execution, status evidence, privacy deletion within a transaction, and both canvas/API integrations. CI runs `tests/section47c-mission-planning.php` in the full release gate.

## Deliberate boundaries
No customer data is analyzed by the Admin planner. A visible plan is not a consent grant or automatic write. Further work in 47D–47I will refine customer context, planning controls, specialist work, UI approval and recovery.
