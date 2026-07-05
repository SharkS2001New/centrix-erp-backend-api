# MySQL slow query log (production tuning)

Use this on the shared MySQL + k3s host to find queries worth indexing or caching.

## Enable (runtime, survives until restart)

```sql
SET GLOBAL slow_query_log = 1;
SET GLOBAL long_query_time = 1;
SET GLOBAL log_queries_not_using_indexes = 0;
SET GLOBAL slow_query_log_file = '/var/log/mysql/mysql-slow.log';
```

`long_query_time = 1` logs queries taking **1 second or more**. Use `0.5` for a noisier sample during a short review window.

## Persist in `my.cnf` / `mysqld.cnf`

```ini
[mysqld]
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mysql-slow.log
long_query_time = 1
log_queries_not_using_indexes = 0
```

Restart MySQL after editing the config file.

## Review top queries

```bash
# Install if needed: pt-query-digest (percona-toolkit)
sudo pt-query-digest /var/log/mysql/mysql-slow.log | head -n 80
```

Or on MySQL 8.0+ with the performance schema:

```sql
SELECT DIGEST_TEXT, COUNT_STAR, ROUND(AVG_TIMER_WAIT/1e12, 3) AS avg_sec, SUM_ROWS_EXAMINED
FROM performance_schema.events_statements_summary_by_digest
ORDER BY SUM_TIMER_WAIT DESC
LIMIT 10;
```

## What to look for

1. **High `Rows_examined`** on `sales`, `sale_items`, `stock_reservations`, `products` — often fixed by composite indexes or removing `LIKE '%term%'` filters.
2. **Repeated identical aggregates** — cache (see report dashboard) or materialized summaries.
3. **N+1 patterns** from the API — batch with `whereIn` / `groupBy` instead of per-row queries.

## After Week 1 changes

Re-run the digest after deploying:

- `sales` composite indexes (`idx_sales_org_*`)
- `stock_reservations` batch overlay in `BranchStockService`
- Report dashboard Redis cache (`CACHE_REPORTS_DASHBOARD_TTL`, default 180s)
- Product catalog summary single-query stock counts (`catalogStockStatusCounts`)
- Prefix-friendly list search (`SqlLikeSearch` on products, sales, customers)
- Route backfill moved off read paths (hourly `erp:backfill-sale-routes` only)

`SqlLikeSearch` only queries Centrix operational tables after org/branch scoping; LightStores legacy archive data uses separate `/legacy-*` APIs.

Compare average query time and rows examined for sales list, product catalog, and `/api/v1/reports/dashboard`.
