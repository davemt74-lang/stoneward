# Section 90B — Stonefellow Admin Agent module discovery

## Delivered

- 23 bounded natural-language Admin intents map to installed Stonefellow workspaces.
- Each reply includes a contextual action button, shown only when the server reports
  the registered `admin_module_navigate` tool contract and its destination appears
  on the browser's explicit Admin view allowlist.
- “Upload a song” offers a direct user-initiated WAV/MP3 file chooser using the
  existing private Media Library uploader; it **does not** silently upload files,
  create catalog tracks, or publish an audio file.
- The Admin Agent's generic AI prompt describes available modules and focuses on
  the actual question; unrelated CRM metrics are omitted. Private catalog, media,
  customer, and knowledge records are not added to third-party model prompts.
- The fan Agent cannot discover or invoke this Admin-only navigation tool.

## Boundaries / follow-up

This is discovery and owner-initiated navigation, **not** autonomous systemwide
write execution. Every destination still uses its own authenticated Admin API,
role checks, CSRF protection, publication review, and billing controls. A later
phase can add specific permission-bound read and reviewed-write tools without
giving a provider direct SQL, arbitrary PHP, credentials, or unchecked authority.

## Verification

- `php tests/section90b-admin-module-routes.php`
- `node tests/section90b-admin-module-client.cjs`
- `node --check admin/assets/agent-canvas.js`
- Existing Section 46 shared-orchestration assertions remain in the release gate.
