#!/bin/zsh
set -euo pipefail
python3 /Users/mac/Documents/projects/centrix-erp-backend-api/tmp-apply-payment-fix.py
cd /Users/mac/Documents/projects/centrix-erp-frontend-web
npx vitest run src/lib/pos-edit-payment-adjustment.test.js
