# Section 91B8E — Private lyric note commands (1.3.179)

After capturing and transcribing a published song excerpt, the signed-in
listener can use the **same chat command path for text and voice** to manage
their own private notes. Commands are handled in the browser before the
general conversational agent request, so corrections do not accidentally
become part of a routine LLM prompt.

## Commands

- `Correct the lyrics to ...` updates the browser's editable draft, preserving
  the machine transcription and clearing any stale analysis report.
- `Add a note to this section: ...` attaches a personal note to the draft.
- `Save this lyric note` explicitly writes the draft, correction, selected
  playback offsets, source label and optional interpretive report through the
  authenticated, CSRF-protected annotation endpoint. This action requires
  the account's private-personalization consent.
- `List my saved lyric notes` displays up to ten of the user's saved records.
- `Show lyric note #3` displays the original and edited transcript separately,
  plus any personal note or historical interpretive report.
- `Edit saved note #3 to ...` updates only the user correction; the original
  transcript and offsets cannot be changed. The earlier report is cleared.
- `Delete lyric note #3` requires an explicit confirmation before deletion.

Full note contents and the list are displayed in the signed-in chat UI but
are not passed to external speech synthesis as a spoken answer. The agent
speaks only a generic confirmation. No annotation data are automatically
added to prompts in future conversations. Broader Agent Brain disclosure
requires a separately authorized sharing feature.

The existing `2026-10-30-060` additive table migration from 91B8D is reused;
no new schema is needed. Run `upgrade.php` on deployment per the normal
Stonefellow release procedure.

## Gate

The Release Gate includes `section91b8e-song-annotation-chat.php` for
owner-bound updates and immutable provenance, and `.cjs` for typed/spoken
dispatch, explicit save, prompt privacy, no automatic TTS of private note
contents, stale-track protection and delete confirmation. Production acceptance
must additionally verify the opt-in settings, voice recognition and browser
capture permissions on the actual HostGator deployment.
