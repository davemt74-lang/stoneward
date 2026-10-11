# Stonefellow v1.3.29 Upgrade Notes

1. Upload/extract the v1.3.29 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, database configuration, secrets, store configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.24** to **1.3.25**.
5. Migration **2026-10-11-026** creates:
   - `press_kits`
   - `press_contacts`
   - `press_outreach`
   - `press_coverage`
   - `press_events`
6. No fan contacts are copied into Press Contacts. Journalists/media contacts remain a separate directory from Fan CRM/newsletter consent.
7. Existing releases, Distribution + Publishing records and Media Library assets remain authoritative; Section 30 references them rather than copying them into a shadow catalog.
8. Open Admin → **EPK + Press**, create/save an EPK as Draft, then attach and explicitly publish its press-ready Media Library assets.
9. Published/Private EPKs must pass current readiness. Release-linked kits also consume current Section 29 distribution readiness.
10. Private EPK links must be rotated/generated from the Admin session. The raw token is shown only at generation time and is carried in the URL fragment.
11. Press outreach uses the existing transactional email configuration. Test email delivery before sending real outreach.
12. Press contacts marked Do Not Contact or Archived cannot receive outreach.

Application: **1.3.29**  
Database schema target: **1.3.25**
