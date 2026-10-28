# Section 91B3A — Governed Knowledge Base note edits (v1.3.165)

First bounded 91B3 operation. An Admin can say:

> Set note for knowledge file "Exact Filename.md" to Updated description.

Stonefellow resolves **exactly one** private Knowledge Base file name; missing or duplicate matches fail closed. Files enabled for the public Agent are excluded because their `notes` field can influence public searches and responses. It prepares a canonical `notes`-only change with the original note, file ID, document revision and whole-index hash. The tool contract is Admin-only and explicitly review-required. No public/fan skill can discover or execute it.

1. Admin Agent Chat displays the target and proposed value; it does not save.
2. Owner opens the review modal and prepares a durable proposal in the existing `agent_action_proposals` ledger.
3. Owner inspects the exact server-normalized payload and SHA-256, types `APPROVE` and separately clicks **Save approved note**.
4. Server claims the single approved execution, rechecks the exact source hash and file revision, and atomically saves via the existing Knowledge Base writer. The saved note is then read back and verified.
5. An interrupted/ambiguous save is marked for human review and never retried automatically. Replaying a confirmed completed proposal yields the existing receipt without another write.

The writer serializes updates with a private lock and compares the approved index hash before saving. All other Knowledge Base actions still use their existing UI. This operation cannot alter document contents, public visibility (`public_agent`), folder, files, hashes or media. No new database migration, external service, VP3 or Gelato change.

Automated contract/fixture tests: `tests/section91b3a-reviewed-knowledge-notes.php`, `tests/section91b3a-reviewed-knowledge-ui.cjs` and the existing full Release Gate. Live HostGator acceptance is still required after deployment.
