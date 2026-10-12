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
rm -f "$stage/site/.gitignore" "$stage/site/.gitattributes"
for file in index.php upgrade.php admin/index.php api/bootstrap.php; do
  test -s "$stage/site/$file" || { echo "Missing required deploy file: $file" >&2; exit 1; }
done
(cd "$stage/site" && zip -X -q -r "$out/stonefellow-deploy.zip" .)
unzip -tqq "$out/stonefellow-deploy.zip"
(cd "$out" && sha256sum stonefellow-deploy.zip > stonefellow-deploy.zip.sha256)
printf 'Built %s from %s\n' "$out/stonefellow-deploy.zip" "$(git -C "$root" rev-parse HEAD)"
