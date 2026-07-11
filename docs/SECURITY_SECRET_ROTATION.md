# Secret rotation checklist (after `.env` or backups were committed)

Run this **before** `scripts/purge-git-secrets.sh` and a force-push.

## Rotate immediately

1. **APP_KEY** — `php artisan key:generate`; all existing API tokens become invalid.
2. **Database** — change `DB_PASSWORD` on MySQL; update k8s/Docker secrets.
3. **Redis** — change `REDIS_PASSWORD` if set.
4. **Mail** — rotate SMTP username/password.
5. **M-Pesa** — rotate consumer key/secret and passkeys in the Daraja portal.
6. **OpenAI** — revoke and reissue API key if it was in `.env`.
7. **Cloudflare R2 backup** — revoke the R2 API token in Cloudflare, create a new access key, update `BACKUP_R2_ACCESS_KEY_ID` / `BACKUP_R2_SECRET_ACCESS_KEY`.
8. **Platform super-admin** — change `PLATFORM_SUPER_ADMIN_PASSWORD` and org admin passwords if dumps were exposed.

## Untrack (keeps local files)

```bash
git rm --cached .env
git rm --cached storage/app/private/backups/database/*.sql.gz
git rm --cached storage/framework/testing/disks/local/backups/testing/*.sql.gz
git commit -m "Stop tracking env and database backup artifacts"
```

## Purge history

```bash
chmod +x scripts/purge-git-secrets.sh
./scripts/purge-git-secrets.sh
git push --force --all
git push --force --tags
```

## After deploy

- Confirm `.env` is only on servers/secrets managers, never in the image or repo.
- Confirm `storage/app/private/backups/` is ignored and not in Docker context (`.dockerignore`).
