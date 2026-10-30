#!/usr/bin/env bash
set -euo pipefail
# Deterministic source-only deploy package from the verified Git HEAD.
root="$(git rev-parse --show-toplevel)"
out="${1:-$root/dist}"
mkdir -p "$out"
out="$(cd "$out" && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/site"
git -C "$root" archive --format=tar HEAD | tar -xf - -C "$stage/site"
rm -rf "$stage/site/.github" "$stage/site/tests" "$stage/site/scripts"
# The separate ElevenLabs Node gateway must not be published under the PHP web root.
rm -rf "$stage/site/speech-gateway"
rm -f "$stage/site/.gitignore" "$stage/site/.gitattributes"
# Discard older static release claims; stamp the actual archive source commit.
rm -f "$stage/site/AUDIT-REPORT.md" "$stage/site/RELEASE-NOTES.md" \
 "$stage/site/TEST-RESULTS.txt" "$stage/site/UPGRADE-NOTES.md" \
 "$stage/site/DEPLOY-MANIFEST.txt"
{
  echo "Stonefellow — exact-source deployment"
  echo "Source commit: $(git -C "$root" rev-parse HEAD)"
  echo "Built at UTC: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "Scope: Stonefellow PHP/JS application source at the commit above"
  echo "Private data: config/secrets, storage and host settings are not included"
  echo "Schema: inspect upgrade.php and api/migrations.php before deployment"
  echo "Pilot readiness: Section 83 local source tests do not constitute external Stripe, POD or mailer acceptance"
  echo "Safety: no Stripe live payment activation"
} > "$stage/site/DEPLOY-MANIFEST.txt"
for file in index.php upgrade.php admin/index.php api/bootstrap.php; do
  test -s "$stage/site/$file" || { echo "Missing required deploy file: $file" >&2; exit 1; }
done
(cd "$stage/site" && zip -X -q -r "$out/stonefellow-deploy.zip" .)
unzip -tqq "$out/stonefellow-deploy.zip"
(unzip -p "$out/stonefellow-deploy.zip" DEPLOY-MANIFEST.txt | grep -Fx "Source commit: $(git -C "$root" rev-parse HEAD)")
(cd "$out" && sha256sum stonefellow-deploy.zip > stonefellow-deploy.zip.sha256)
printf 'Built %s from %s\n' "$out/stonefellow-deploy.zip" "$(git -C "$root" rev-parse HEAD)"
