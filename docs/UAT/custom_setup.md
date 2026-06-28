# UAT — Custom setup (`custom`)

**Use when:** Super-admin enables a bespoke module mix not covered by a fixed profile.

## Setup

- [ ] Tenant provisioned with profile **Custom setup**
- [ ] Applications toggled intentionally (note preview panel before register)
- [ ] Setup saved as **provisioning template** for reuse (optional)
- [ ] Recommended roles match enabled modules (preview + registration response)
- [ ] Administrator login channels match enabled workspaces

## Verify module gating

For each enabled application, confirm the workspace appears on login and sidebar matches:

| Application | Verify |
|-------------|--------|
| External ERP (POS) | `/pos` checkout works |
| Backoffice | Sales, inventory, purchasing pages load |
| Distribution | Fulfillment dispatch and trips |
| Accounting | Journal entry and P&L |
| Human Resources | Employees and payroll |
| Administration | Users and roles (or platform admin if off) |

For each **disabled** application, confirm:

- [ ] Workspace not on login picker
- [ ] Direct URL returns 403 or hidden nav

## Clone / template

- [ ] Load saved provisioning template restores module toggles
- [ ] Clone from existing org copies applications and sales platform settings

## Sign-off

| Role | Name | Date | Pass |
|------|------|------|------|
| Customer sponsor | | | |
| Platform admin | | | |
