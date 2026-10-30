# Section 91B8F — Explicit Agent Brain sharing for lyric annotations (1.3.180)

Every saved lyric annotation stays **private by default**. Saving a note does
not authorize an AI provider to read it in future chat. A signed-in listener
may separately choose a scope for each note:

| Scope | Relevant future signed-in Agent chat may receive |
| --- | --- |
| `private` (default) | Nothing from the note |
| `summary` | Song identity, playback offsets, personal note and a bounded excerpt of an existing interpretive report; **no lyric transcript** |
| `full` | The above plus the user-corrected (or machine) lyric draft, labeled unverified and bounded to 2,600 characters |

Both sharing choices require account personalization consent **and** a
per-note browser confirmation explaining configured AI provider disclosure.
Summary sharing requires a personal note or interpretive report to share.
Only a relevant lyric/song-memory question can retrieve a shared note; the
context includes at most two notes. The public-facing Profile Agent and
the independent response evaluator do not receive these notes.

## Chat/voice commands

- `List my saved lyric notes` — includes the current sharing status.
- `Share lyric note #3 with Agent Brain` — opt into **summary** scope.
- `Share full lyric note #3 with Agent Brain` — separately confirm sharing
  an unverified lyric draft in **full** scope.
- `Which lyric notes are shared with Agent Brain` — private status list.
- `Show lyric note #3 sharing history` — private consent-change history.
- `Stop sharing lyric note #3` — immediate per-note revocation, also rotates
  the current browser's agent conversation so recent paraphrases are not
  automatically reused in the next chat.

These commands are handled before the conversational LLM. Note lists and
audit details display privately rather than being sent to voice synthesis.
Editing a previously shared note revokes sharing, clears the prior report,
and requires fresh consent for the new draft. Deleting a note deletes its
sharing audit entries too. Opting out of account personalization suppresses
all shared-note retrieval, while revocation still works.

**Revocation stops future automatic retrieval.** It cannot erase content
already processed by an external AI provider or quoted in an earlier saved
conversation. The listener can clear Agent conversation history separately
through the existing account privacy controls.

## Storage / migration

Migration `2026-10-30-061` adds `agent_share_scope` and `agent_shared_at`
to the existing owner-scoped annotation table and creates a private,
owner-scoped sharing event history. It is additive and upgrades existing
91B8D installations with all notes defaulting to `private`.

Run `upgrade.php` after deploying the normal complete source ZIP. Tests:

- `tests/section91b8f-selective-song-memory.php` validates legacy schema
  migration, privacy defaults, opt-in, summary/full boundaries, owner isolation,
  opt-out, revocation, and edit/delete consequences.
- `tests/section91b8f-selective-song-memory.cjs` validates the same text/voice
  command path, confirmation, summary/full selection, private review and
  revocation behavior.

Browser acceptance must additionally verify real voice recognition, logged-in
consent settings, provider calls, and the user's HostGator migration.
