# Stonefellow 88D3G4K — Crew Task Lifecycle, Recovery and Editorial Coordination

**v1.3.133 · schema 1.3.66 · migration 057**

G4K deepens the existing G4J role-assigned Artist World task system, not a separate autonomous agent. The same Creative Capture contributor view and Admin Creative Director review screen now support progress reporting, reassignment after progress, evidence-linked recovery of rejected work, and a private chronological task activity stream.

**Contributors** see only tasks assigned to their currently authorized team role and recent activity for those tasks. They can write progress updates (minimum 15 characters), and can subsequently submit owned capture IDs or their assigned editorial proposal evidence. Progress is explicitly informational and cannot itself complete, render, publish, transfer, or clear media.

**Editors** can assign/reassign proposed or in-progress tasks to active, role-eligible users. An old assignee immediately loses access to the task and notifications, even if previously assigned; revoked roles also lose access. On rejected work, an editor can reopen the task only with a new eligible assignee and a meaningful (25+ characters) recovery explanation. A reopen resets active evidence/review state but preserves the historical rejected evidence and review basis in the immutable events table. Previously completed work cannot be reopened. Reopened work requires new submitted evidence and full independent editing review again.

**Events and in-app notifications:** An additive private table stores task creation, assignment, progress, evidence submission, human completion approval/rejection, reassignment, and reopening, with actor, timestamp, status transitions, and the exact reviewed evidence ID when available. It is accessible only via the existing authenticated production-editor or currently assigned team-member scopes. The app displays a bounded recent-activity timeline. Notifications are private **in-app activity reads**, not unsolicited emails or background broadcasts. Existing Team Activity records remain. Crucially, a task status change, event insert, and existing audit entry share the same SQL transaction so failures cannot create phantom activity.

**Agent Brain:** Its existing read-only private editorial summary reports how many tasks are in progress or need rejected-work correction and displays a bounded list of task events; no tool execution or approval authority is granted.

**Certification:** `php tests/section88d3g4k-lifecycle.php` covers state transitions, provenance, role revocation, current-assignee scoping, rejection recovery, duplicate/replay prevention, audit rollback, source review re-approval and UI wiring, reusing the G4J fixture. `php tests/section88d3g4k-release.php` locks schema and version. Prior G4A–G4J, MySQL, FFmpeg, private rendering and checksum packaging CI remain required. Deployment requires backing up existing data and running `upgrade.php` for migration 057.

No task activity or approval implies publication rights, likeness clearance, external AI consent, music licensing, or render authorization.
