# Stonefellow v1.3.28 Upgrade Notes

## Hardened Section 29 build

If upgrading from the earlier v1.3.28 deploy, upload/extract the hardened deploy over the installation. **No additional database migration is introduced**; schema target remains **1.3.24**.

Existing distribution packages built by the earlier implementation do not contain the new delivery-source fingerprint. They are intentionally treated as stale and should be rebuilt from Admin → Distribution + Publishing before download/submission.

The hardened preflight now requires distributor-ready managed assets:
- front cover attached in Media Library, square JPG/PNG, at least 3000×3000;
- one managed WAV delivery master per track, at least 44.1 kHz / 16-bit;
- valid ISRCs, UPC/EAN, rights clearance and required release metadata.

Run `/upgrade.php` as usual after deploying. It will confirm the existing **1.3.24** schema is current; there is no new Section 29 hardening migration.

