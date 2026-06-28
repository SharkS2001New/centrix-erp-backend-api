# User acceptance testing (UAT)

Repeatable go-live checklists per deployment profile. Run each checklist on a **fresh provisioned tenant** (or staging clone) before production cutover.

## How to use

1. Super-admin registers the tenant with the target profile (or custom setup).
2. Note the **recommended roles** and **onboarding steps** returned after registration.
3. Create staff users with the suggested roles.
4. Walk through the checklist for that profile.
5. Sign off each section before go-live.

| Checklist | Profile | Primary users |
|-----------|---------|---------------|
| [small_shop.md](small_shop.md) | `small_shop` | Shop owner, branch manager |
| [supermarket.md](supermarket.md) | `supermarket` | Cashier, branch manager |
| [wholesale_retail.md](wholesale_retail.md) | `wholesale_retail` | Cashier, mobile rep, warehouse, accountant |
| [distribution.md](distribution.md) | `distribution` | Warehouse, dispatch, mobile rep, driver |
| [custom_setup.md](custom_setup.md) | `custom` | Any bespoke module mix |

## Regression smoke (all profiles)

After any release, run at minimum:

- Login with company code + staff credentials
- `GET /api/v1/erp/capabilities` matches expected modules
- One product visible in catalogue
- One completed sale (channel appropriate to profile)
- Stock on hand report loads
