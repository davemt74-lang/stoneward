# Section 91B8D — Private song-section annotation storage (1.3.178)

This stage provides an authenticated, owner-scoped persistence layer for
explicitly saved song-section annotations. It follows Sections 91B8A–C,
which capture a limited playback clip, create an **unverified** transcript,
and perform user-requested analysis. No audio bytes are stored here.

## Storage contract

- `api/agent-song-annotations.php` handles signed-in `GET` (list notes),
  and CSRF-protected `POST` `{action:"save"}` or `{action:"delete",id}`.
- Every query uses the server-authenticated account ID. Client-provided IDs
  cannot select a different owner.
- A save must reference one published playable catalog song and a valid
  continuous 0.5–90-second playback range. The client-provided song title
  and album name are ignored; both are sourced from the catalog.
- Original machine draft, optional user-corrected draft, user note, analysis
  question, and derived report are separate fields. Neither machine nor
  user-edited draft is misrepresented as authoritative lyrics.
- Captured time and record creation time are stored as UTC timestamps.
  The playback range uses millisecond offsets within the song.
- Private Agent Brain personalization must be enabled for a save. A user
  can still inspect or delete an existing annotation after opting out.
- Max 250 owner-owned notes, bounded text lengths, no audio files, and
  explicit deletion. The `2026-10-30-060` additive upgrade creates the
  SQLite or MySQL table, with lazy `ensure_schema` for installations that
  haven't yet run `upgrade.php`.

## Consent boundary

This stage **does not** automatically send saved annotations, lyric text,
or past reports to an LLM in future conversations. The fan's Agent Brain
read endpoint may return their own private notes to the signed-in browser,
but that is not authorization for external AI sharing. Broader retrieval
and conversational disclosure require separately explained consent.

## Delivery and tests

The next stage connects the visible chat controls for review, correction,
explicit save/list/delete. This stage's database API is exercised by
`tests/section91b8d-private-song-annotations.php` (including cross-account
read/deletion, provenance, invalid inputs, and opt-in) plus its Node
companion and Release Gate syntax checks.
