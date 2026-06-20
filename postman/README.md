# Postman — Centrix ERP API

## Import

1. Open Postman → **Import** → select both files:
   - `centrix-erp-api.postman_collection.json`
   - `Local.postman_environment.json`
2. Choose environment **Centrix ERP — Local** (top-right).
3. Run **Auth → POST auth/login** — the test script saves the Sanctum `token` automatically.
4. All other requests use **Bearer {{token}}** (collection auth).

## Variables

| Variable | Default | Use |
|----------|---------|-----|
| `baseUrl` | `http://localhost:8000/api/v1` | API root |
| `token` | *(set by login)* | Sanctum bearer |
| `id` | `1` | Generic resource id |
| `cartId` | `1` | Cart operations |
| `saleId` | `1` | Sales / payments |
| `sessionId` | `1` | Till sessions |
| `customerNum` | `1` | Customer statement |

## Regenerate after route changes

From project root:

```bash
composer postman
# or: php artisan postman:generate
```

## Demo login

```json
{
  "username": "admin",
  "password": "password"
}
```

## Folders

- **Auth** — login (no token), logout, me
- **Sales Operations** — carts, checkout, order transitions
- **Reports** — all `/reports/*` endpoints
- **HR & Payroll** — employees, departments, pay periods, payroll
- Plus CRUD folders for catalog, inventory, purchasing, accounting, etc.
