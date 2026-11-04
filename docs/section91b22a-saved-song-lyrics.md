# 91B22A — Import song lyrics once

Stonefellow now generates lyric drafts for the catalog listening copy and reuses the saved text when a listener chooses **Listen & Interpret**. The old mobile transcription URL serves the same saved-only lookup. Record Studio and Record Clip remain separate.

## Add lyrics to a song

1. Upload audio through the existing catalog or Media Library flow. Assign and publish the **listening copy**. Publication queues lyrics for that exact audio version. Private masters and unattached uploads are excluded.
2. Open the song in **Admin → Catalog** to check its lyric import progress. For a previously published song, choose **Import song lyrics** on that song's editor. The OpenAI key comes from **Admin → AI Providers**.
3. Choose **Review lyric segments** to open that song in **Song Moments**. Correct the draft words or timing, set each reviewed segment to **Approved by administrator**, and confirm publication. Unverified drafts are visible to administrators only.
4. Choose **Save approved lyrics to song** to write the reviewed text into the existing Catalog lyrics field used by Songbook. This is an explicit replacement of existing lyrics. Imported drafts never overwrite it automatically.

If a song is republished with a different listening copy, the old ranges are no longer served. The new copy gets its own import. Manually authored catalog lyrics are preserved; lyrics copied from the previous timeline are cleared when the source changes.

## Playback and interpretation

- Play a song and choose **Listen & Interpret**. **Stop** reads saved blocks that overlap the selected interval. Closing the lyric sidebar keeps the orb Stop control available. Seeking discards the old selection.
- The Song Timeline highlights the current lyric block. Use **Jump** to seek or **Ask about this range** to open the lyric review before interpretation.
- Listener section selection makes no audio upload, transcription call, or Active Token debit. Interpretation is still a separate explicit request to the configured chat model. The agent distinguishes reviewed saved lyrics, documented artist sources, and its own interpretation.
- **Song moments in this section** shows up to three published stories, public photos, and approved comments of each kind whose timestamp falls within the selected range. Stop previews the public items. Interpret lyrics checks publication and moderation again before using them. The model receives text descriptions and captions for photos.
- An interpretation may also use up to two recent overlapping notes from the signed-in listener when personalization is on and the listener shared each note with Agent Brain. The app uses personal notes and prior report excerpts. Earlier lyric drafts stay out of this context. A saved note's time range is historical and may refer to a previous audio version. The public moments list contains no private notes.
- `gpt-transcribe` produces text, so imported ranges reflect approximately minute-long audio blocks with a two-second overlap. Block timing is approximate; the editor supports manual alignment. Do not infer line or word timestamps. A selected interval can show words from an overlapping block beyond its edges.

## Processing and deployment

The import is bounded to managed MP3/PCM WAV listening copies, 64 MiB source size, 20 MiB per excerpt, and 15 minutes per song. Excerpts use the existing pure PHP slicer and are deleted after use. Jobs persist by track, source hash, model, and import profile; one provider operation is claimed per block. A cached block can finish saving without another provider request. An unknown provider outcome never automatically retries. Administrators can pause before the next block, and repeat imports for the same source preserve existing edits.

The application lazily creates the additive tables and columns on SQLite or MySQL. The root deployment ZIP excludes host configuration, secrets, catalog data, and media/runtime storage. Extract directly into the application root; existing `config/store.php` takes precedence over the shipped defaults.

For unattended imports on shared hosting, run `php /path/to/stonefellow/api/cli/song-lyrics-worker.php --admin-id=ADMIN_ID` from a host cron job. Each run checks that the specified administrator is active and advances at most one queued block. The command is CLI-only. The Catalog song editor can also run or pause that song's import while the administrator is signed in.

Browser and database certification uses synthetic provider responses. On a staging site with an enabled OpenAI API key, publish a short managed listening copy, review its import, and select the same section twice. Confirm the second selection reuses saved lyrics. Check the sidebar and Stop control on a physical iPhone before treating device acceptance as complete.
