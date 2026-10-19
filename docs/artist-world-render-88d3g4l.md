# Stonefellow G4L — Governed Production Scheduling and Crew Availability

**v1.3.134 · schema 1.3.67 · additive migration 058**

G4L extends the existing role-governed G4J/G4K production tasks with **explicit human-set UTC deadlines, urgency priorities, and self-reported contributor availability**. The user-facing Creative Capture canvas includes a 0–31 day availability calendar form (available, limited, unavailable) visible only to that contributor and authorized production editors. The Admin Creative Director task panel now accepts due dates, priority and a documented reason, and displays overdue, due-soon, unassigned and availability-conflict signals. A scheduled task does not change assignment or source evidence.

Schedules, availability and their associated task activity/audit entries are committed in SQL transactions. Required audits cannot fail silently; a transaction rollback leaves no false deadline or availability. Invalid calendar dates, malformed deadlines, forged timezone offsets, unknown priorities, excessive windows and insufficient reasons are rejected. Contributor availability windows that overlap are replaced on explicit self-service submission. An editor cannot impersonate a contributor by editing their availability. Scheduling is blocked for already approved or rejected work.

The existing private Agent Brain **only reads** scheduling snapshots and suggests review actions for overdue work, scheduling conflicts, unassigned tasks or upcoming deadlines. No automated reassignment, reminder delivery, emailing, calendar entry, publication or rendering is performed. The in-app alerts are computed on the current data when the user opens the interface, not a claimed background watcher. Existing approval, access controls, consent, identity and source-use review remain intact.

**Security:** Crew sees only the schedule on tasks currently assigned to them with an active qualifying role. Editors can review the scheduling board; other contributors cannot read another person's availability notes or production tasks. Role revocation or reassignment immediately removes access to previous task scheduling information. No source media is sent to an external provider.

**CI acceptance:** `php tests/section88d3g4l-scheduling.php` exercises exact UTC time comparisons, overdue thresholds, availability conflict correction, unauthorized scheduling, role isolation, audit rollback, and non-mutating Agent Brain suggestions on the already certified G4K task fixture. `php tests/section88d3g4l-release.php` verifies the v1.3.134 release, additive migration 058, unchanged private approval boundaries and release gate. Prior G4A–G4K, real MySQL/FFmpeg and ZIP-integrity workflows remain required.

After backing up the current HostGator installation, deploy the full cloud ZIP and run `upgrade.php`. Actual contributor availability, deadlines and field operating dates should be reviewed manually.

**Next optional phase:** G4M — Editorial calendar view, contributor-initiated deadline extension requests and owner-reviewed reminders.
