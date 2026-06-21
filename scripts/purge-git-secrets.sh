#!/usr/bin/env bash
# Remove committed secrets from git history. Run only after rotating credentials.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! command -v git-filter-repo >/dev/null 2>&1; then
  echo "Install git-filter-repo first: brew install git-filter-repo" >&2
  exit 1
fi

echo "This rewrites git history. Coordinate with your team before force-pushing."
read -r -p "Rotate secrets first and type YES to continue: " confirm
if [[ "$confirm" != "YES" ]]; then
  echo "Aborted."
  exit 1
fi

git filter-repo --force \
  --path .env \
  --path storage/app/private/backups/database/pos_erp_2026-06-20_172929.sql.gz \
  --path storage/framework/testing/disks/local/backups/testing/testing-backup.sqlite_2026-06-21_075947.sql.gz \
  --invert-paths

echo "Done. Force-push all branches: git push --force --all && git push --force --tags"
echo "Then invalidate every secret that was ever in .env or database dumps."
