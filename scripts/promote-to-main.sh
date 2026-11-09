#!/usr/bin/env bash
# Local helper: run tests, then rebase current master tip onto main and push.
# Prefer the GitHub Action for routine promotes; use this for a one-off sync.
#
# Compare both tips, rebase (not merge/overwrite) so commits that exist only
# on main are kept, master-only commits replay on top, then fast-forward main
# only (never force-push).
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

echo "==> Running smoke + critical gates (full suite: php artisan test --testsuite=Unit)"
php artisan test --testsuite=Smoke --fail-on-skipped
php artisan test --testsuite=Critical --fail-on-skipped
php artisan test tests/Feature/SalesCartCheckoutStockTest.php \
  --filter='test_create_cart_and_add_line_reserves_stock|test_checkout_completes_sale_and_deducts_ledger|test_clear_cart_releases_reservation|test_inventory_availability_endpoint|test_held_order_can_be_cancelled|test_hold_order_can_be_restored_to_cart|test_checkout_rejects_expired_cart_holds_when_stock_is_gone|test_cart_rejects_unknown_product_code' \
  --fail-on-skipped

echo "==> Fetching remotes"
git fetch origin master main

MASTER_SHA="$(git rev-parse HEAD)"
MAIN_SHA="$(git rev-parse origin/main)"
BASE_SHA="$(git merge-base "${MAIN_SHA}" "${MASTER_SHA}")"

echo "==> Comparing branches before promote"
echo "main:   ${MAIN_SHA}"
echo "master: ${MASTER_SHA}"
echo "base:   ${BASE_SHA}"

echo ""
echo "==> Commits only on main (must be preserved)"
git log --oneline "${MASTER_SHA}..${MAIN_SHA}" || true

echo ""
echo "==> Commits only on master (will replay onto main)"
git log --oneline "${MAIN_SHA}..${MASTER_SHA}" || true

if git merge-base --is-ancestor "${MASTER_SHA}" "${MAIN_SHA}"; then
  echo "main already contains ${MASTER_SHA:0:7}"
  exit 0
fi

git checkout -B promote-temp "${MASTER_SHA}"

if ! git rebase "${MAIN_SHA}"; then
  git rebase --abort || true
  git checkout master
  echo "Rebase conflict promoting master onto main. Resolve manually (never force-push)." >&2
  exit 1
fi

PROMOTE_SHA="$(git rev-parse HEAD)"

if ! git merge-base --is-ancestor "${MAIN_SHA}" "${PROMOTE_SHA}"; then
  git checkout master
  git branch -D promote-temp 2>/dev/null || true
  echo "Aborting: rebase result lost commits from main (${MAIN_SHA:0:7}). Nothing pushed." >&2
  exit 1
fi

if [[ "${PROMOTE_SHA}" == "${MAIN_SHA}" ]]; then
  git checkout master
  git branch -D promote-temp 2>/dev/null || true
  echo "Aborting: promote tip equals old main; unexpected empty promote." >&2
  exit 1
fi

echo ""
echo "==> Rebase result (main preserved + master replayed)"
git log --oneline "${MAIN_SHA}..${PROMOTE_SHA}"

git checkout -B main "${MAIN_SHA}"
git merge --ff-only "${PROMOTE_SHA}"

NEW_MAIN="$(git rev-parse HEAD)"
if ! git merge-base --is-ancestor "${MAIN_SHA}" "${NEW_MAIN}"; then
  echo "Aborting before push: new main would drop previous main tip." >&2
  exit 1
fi
if [[ "${NEW_MAIN}" != "${PROMOTE_SHA}" ]]; then
  echo "Aborting before push: main tip does not match rebased promote tip." >&2
  exit 1
fi

git push --ff-only origin main
git checkout master
git branch -D promote-temp 2>/dev/null || true
echo "==> Done. main is at $(git rev-parse --short origin/main) (no force)"
