#!/usr/bin/env bash
set -euo pipefail
# Only a disposable loopback CI database. Generate a fresh credential per job.
database="${1:?test database name required}"
[[ "$database" =~ ^[a-zA-Z0-9_]+$ ]] || exit 2
ci_password="$(openssl rand -hex 32)"
printf '::add-mask::%s\n' "$ci_password"
printf 'SF_TEST_MYSQL_PASSWORD=%s\n' "$ci_password" >> "${GITHUB_ENV:?GitHub job environment required}"
docker run -d --name sf-test-mysql -p 127.0.0.1:33306:3306 \
  -e MYSQL_ROOT_PASSWORD="$ci_password" -e MYSQL_DATABASE="$database" mysql:8.0 >/dev/null
for attempt in {1..60}; do
  if docker exec -e MYSQL_PWD="$ci_password" sf-test-mysql mysqladmin ping -h 127.0.0.1 -uroot --silent >/dev/null 2>&1; then
    exit 0
  fi
  sleep 2
done
printf 'Disposable MySQL failed to start.\n' >&2
exit 1
