# Artist World 88D2H — Production integrity hardening

Release: **Stonefellow v1.3.113**, database target **1.3.50** (migration `2026-10-18-041`).

## Changes shipped

- **H1 / PR #210**: external Admin research providers receive only allowlisted aggregate counters and the current explicitly submitted question. Previous Admin conversation turns, production evidence and unstructured feedback do not cross the provider boundary.
- **H2 / PR #211**: capture submission, activity audit and immutable technical evidence commit atomically. Failure cleanup distinguishes newly-created media from deduplicated Media Library assets.
- **H3 / PR #212**: story graph links require approved capture submissions and verified scene or transcript records.
- **H4 / PR #213**: separate human rights decisions for `external_ai` and `publication`. Each requires an editor action with a substantial basis, is independently revocable and appears in the Admin capture review queue.
- **H5**: release identity, additive migration, new integrity report entry, updated MySQL schema tests and deploy package certification.

## Reviewing contributor rights

Contributor `rights_attested` and `people_release_status=not_applicable` are **not** permissions for sending media to providers or publishing. Only the Admin capture API may record a purpose-scoped review.

Authenticated Admin POST to `admin/api/story-capture.php`, with the application's normal CSRF protections:

```json
{"action":"review_rights","submission_id":42,"purpose":"external_ai","decision":"cleared","basis":"Signed artist and venue permissions reviewed; agreement reference in private rights records."}
```

`purpose` is `external_ai` or `publication`; `decision` is `cleared` or `restricted`. A rights status is not a legal opinion or an automatic consent determination. The original release documentation should remain in controlled private records. Restrict or revoke a clearance immediately if permissions change. New processing must check the current status before execution.

## Installation and evidence limitations

Run the existing `upgrade.php` backup-and-migration workflow before using the new rights actions; the added table is safely additive. Keep storage, host configuration, database credentials and customer records outside deploy archives.

The Admin rights API is implemented; a dedicated inline editor control for clearance remains to be built. **Automatic speech-to-text, frame analysis, provider uploads, model processing queues and final AI video rendering are not implemented by this release.** End-to-end HostGator file-upload failure injection, MySQL transactional fault testing, and post-merge deployment certification still require validation.

CI gates verify PHP regressions, SQLite review/rollback/ACL tests, a real MySQL 8 rights-table schema, and an exact-source deployment checksum. Field certification is separate from passing these automated gates.
