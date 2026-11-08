#!/usr/bin/env bash
# Local helper: run tests, then merge current master tip into main and push.
# Prefer the GitHub Action for routine promotes; use this for a one-off sync.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$BRANCH" != "master" ]]; then
  echo "Switch to master before promoting (currently on ${BRANCH})." >&2
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Working tree is dirty. Commit or stash before promoting." >&2
  exit 1
fi

echo "==> Running unit + feature tests"
php artisan test --stop-on-failure

echo "==> Fetching remotes"
git fetch origin master main

MASTER_SHA="$(git rev-parse HEAD)"
echo "==> Promoting ${MASTER_SHA:0:7} to main"

git checkout main
git pull --ff-only origin main || git reset --hard origin/main

if git merge-base --is-ancestor "$MASTER_SHA" HEAD; then
  echo "main already contains ${MASTER_SHA:0:7}"
  git checkout master
  exit 0
fi

if ! git merge --ff-only "$MASTER_SHA"; then
  git merge --no-ff --no-edit "$MASTER_SHA" \
    -m "chore: promote master ${MASTER_SHA:0:7} to main after tests"
fi

git push origin main
git checkout master
echo "==> Done. main is at $(git rev-parse --short origin/main)"
