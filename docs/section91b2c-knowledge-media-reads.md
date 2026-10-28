# Section 91B2C — Admin Knowledge Base and Media Library reads (v1.3.164)

The existing authenticated Admin Agent Chat now resolves three real, read-only inventories: Knowledge Base document metadata, Media Library assets/usage, and media attachment relationships. All sources are the same local Admin-managed index and media database used by the existing application; no external model receives the underlying private records.

Examples:

- “List Knowledge Base documents.”
- “Which documents are private?”
- “Show Media Library assets.”
- “List unused media files.”
- “Which attachments are on tracks?”

Only bounded document IDs, names, format and Agent visibility are returned. Document text, notes, stored paths and unpublished content are deliberately excluded. Media inventory shows asset ID, safe title, category and number of attachments. Attachment inventory shows asset ID/title, category, target entity type/ID, role and public flag. No stored paths, technical tags, file hashes, source URLs or raw link metadata are projected.

The inventory and attachment selectors count matching database records before returning a bounded page and explicitly report truncation; they do not attempt to load whole media files or make them public. Failures return a neutral unavailable message with no mutation. The existing Admin Agent endpoint authenticates the administrator before reaching any read, and skills/tools are not discoverable in public or customer contexts.

Ordinary “Open Knowledge Manager” and “Open Media Library” navigation is preserved. Existing catalog, release and shows reads, and exact-approval write governance, are unchanged. No migration is needed; the existing media schema is used. Real HostGator records and browser Agent Chat still require live acceptance after deployment.

Automated acceptance: `php tests/section91b2c-agent-knowledge-media-reads.php`; full CI release gate includes the test, PHP/JS syntax, existing agent security and integration regressions.
