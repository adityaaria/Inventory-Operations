# Operations runbook

Requirement scope: DB-01, AUTH-01/02, ERR-01, ARCH-01/02, TEST-01/02/03 and release hardening. User-approved operational additions, not new order/stock business features. See ADR-007 for boundaries.

## Backup, retention and recovery

Python 3.11+ and Docker Compose are required on the operator host. Backups contain confidential business/user data: `var/backups` is Git/Docker ignored, mode 0700; archives/manifests are 0600. No production credential is passed as a command argument or printed. Application DB scoped credentials are taken inside the DB container. No concurrent schema migrations/DDL while dumping; InnoDB data is a single-transaction snapshot. Stop writers for an exact source/restored digest comparison in a drill.

```sh
python3 scripts/db-operations.py --project inventory-operations backup
python3 scripts/db-operations.py prune --keep 7
python3 scripts/db-operations.py prune --keep 7 --apply
```

Prune defaults to dry run; verifies every archive before deleting any older one. Keep at least seven daily valid backups as an initial policy, plus off-host encrypted copies with separately controlled access. Local copies alone do not survive host/disk loss. Schedule backups daily and check alert output; no scheduler or remote storage destination has been configured on the user's machine.

For production, supply `--file compose.production.yaml --env-file /secure/path/production.env --project inventory-production` before the operation. Protect the env file (0600), never commit it. RPO proposal: 24 hours with daily backup; RTO must be accepted from a representative restored dataset and target hardware, not inferred from the small local drill. The fingerprint checks row counts/content; also confirm keys/constraints from the schema and integration suite.

Restore into an EMPTY disposable target, never over the current application's DB:

```sh
docker compose -p inventory-restore-drill -f compose.restore.yaml up -d --wait app
python3 scripts/db-operations.py --project inventory-restore-drill --file compose.restore.yaml restore /absolute/path/inventory-TIMESTAMP.sql.gz
docker compose -p inventory-restore-drill -f compose.restore.yaml exec -T app php scripts/database-fingerprint.php
```

Both the project prefix `inventory-restore-*` and database suffix `_restore` are required; label, empty table count, gzip integrity and manifest SHA256 are checked before import. An import failure leaves a disposable partial target: discard it and retry into a new empty target. Do not bypass the empty-target guard. A checksum verifies integrity, not authenticity: accept only operator-trusted backups. Compare all table counts/digests and functional receipt/issue/auth/report behavior before cutover. The fingerprint exposes digests/counts, not row contents.

For verification-only data, cleanup is `docker compose -p inventory-restore-drill -f compose.restore.yaml down -v`. NEVER use that command on a promoted recovery volume. Sessions are deliberately not copied in a DB backup; users sign in again after recovery. Include schema, users, source orders, stock, ledger, audit and all other tables together; never repair stock by copying a balance table alone.

## Production topology and promotion

`compose.production.yaml` is standalone; do not merge it with local compose.yaml. DB/FPM have no published ports; only Nginx binds explicitly selected host interfaces. Defaults are loopback. PHP-FPM omits debugging/coverage/dev packages. The application/web root filesystem is read-only; sessions and logs are persistent. No demo seed is automatically loaded. Health intentionally fails until the restored schema is available.

1. Choose a new restore project and strong target credentials in a protected environment file (`RESTORE_DATABASE` ending `_restore`, `RESTORE_USERNAME`, `RESTORE_PASSWORD`, `RESTORE_ROOT_PASSWORD`). Compose restore defaults are disposable demo-only credentials.
2. Create that empty target, restore a trusted curated backup, verify counts/digests and business flows, and remediate demo credentials/accounts before serving real traffic.
3. Stop the restore project's containers. Keep its named `PROJECT_mysql-data` volume. Do not run `down -v`.
4. Copy `deploy/.env.production.example` outside Git. Set `DB_DATABASE/DB_USERNAME/DB_PASSWORD/DB_ROOT_PASSWORD` to the already-existing restored DB credentials, `DB_VOLUME_NAME` to its verified volume, `TLS_DIRECTORY` to a directory containing `fullchain.pem` and `privkey.pem`, and bind interfaces/ports deliberately. MySQL environment variables do not change existing users/passwords on a populated volume.
5. Build and start: `docker compose -p inventory-production -f compose.production.yaml --env-file /secure/path/production.env up -d --build --wait`. Only one DB container may mount the restored MySQL data directory at a time.
6. Verify trusted TLS, login/logout/roles, cookies, reports, inventory/ledger invariants and operational monitoring. The internal `/health/ready` is loopback-only; `/health/live` is a minimal public Nginx liveness probe.

Use an actual public/trusted CA certificate for deployed users; install renewal and reload Nginx after renewal. The automation's one-day self-signed certificate is trusted explicitly only in local tests. HTTP redirects assume standard HTTPS port 443; if deploying a custom external port, place a reverse proxy or adapt the redirect origin explicitly. Rollback application code must remain schema-compatible; rollback data is a separate recovery decision and must account for transactions after the backup.

## Monitoring and alert consumption

```sh
python3 scripts/monitor.py --project inventory-operations --backup-directory var/backups
python3 scripts/monitor.py --project inventory-production --file compose.production.yaml --env-file /secure/path/production.env --backup-directory /secure/path/backups
```

Healthy returns JSON/exit 0; alerts return JSON/exit 1. Monitor DB/app/web container health, application readiness, application error count (default >=1 in 15 minutes), malformed logs, and newest backup integrity/age (default 26 hours). Probe failures are alerts, not silent success. No webhook/email/Slack traffic is sent. A production scheduler should run this every five minutes and route exit 1 to a team-approved alert destination. Alert ownership and escalation need deployment-owner assignment.

Application logs: current app.log plus five rotated archives at 10 MiB each (one oversize entry may exceed the limit); rotation/append share a process lock. Docker logs retain five 10 MiB files per service. Nginx logs status/method/duration/request ID without query strings/cookies/request bodies. Generic PHP error events omit raw exception messages; class/file/line remain for investigation. Transactional business audit is separately persisted in MySQL.

For `application_not_ready`, inspect DB health/volume/storage permissions; for `application_error_threshold`, inspect local app logs and Docker PHP/Nginx logs; for stale/corrupt backups, fix backup generation and perform a recovery drill before pruning. Avoid exposing logs or backup files over HTTP. Disk-capacity/certificate-expiry/off-host storage monitoring belongs to the host operator and is not implied by container health.

## Automated verification and limits

```sh
python3 -m unittest discover -s tests/Operations -v
docker compose --profile quality run --build --rm test
docker build --target runtime -t inventory-operations-app:latest .
# Build production images using valid environment substitutions; no deployment is required.
docker compose -f compose.production.yaml --env-file /secure/path/production.env build
python3 scripts/verify-operations.py
```

The verification script reserves localhost 18087/13309/18088/18443 for newly named disposable projects. It runs business HTTP flows, protected backup, full-table restoration, guard rejections, trusted local HTTPS/header/cookie checks, runtime package isolation, error/DB-loss alert checks and 8 independent sessions with a 320-request burst plus 3,200 reads paced over 60 seconds. It cleans only its own newly created projects; main application data is never mutated. Raw DB dumps, TLS keys and manifests stay in ignored var. Evidence JSON contains counts/digests/results only. A default 2-second p95 read budget is an initial local acceptance target, not a production SLA.

The GitHub workflow uses official checkout pinned to an immutable commit, read-only repository permissions, locked dependency installs, isolated tests and production builds. It publishes/deploys nothing. Hosted CI execution and branch protection require remote workflow availability/configuration; local commands do not prove those controls are enabled.

Physical acceptance uses [Android/iPhone checklist](device-acceptance.md). Business insight and unanswered trainer policies are in [DECISIONS_PENDING](../planning/DECISIONS_PENDING.md).

## Encrypted backup scheduling

Copy `deploy/automation/operations.example.json` to a private operator configuration and replace root, Docker/OpenSSL paths, Compose/env file, recipient certificate, backup/replica/status directories. Create private directories (0700) beforehand. Keep the recipient private key separately with the recovery owner; it is unnecessary on the scheduled backup host. Use a durable operator-owned certificate, not verification test keys. If the replica destination is mounted storage, set `replica_mount` so the job refuses to back up into an unmounted local directory.

Run `python3 scripts/operations-job.py --config /private/path/operations.json` manually first. Review its JSON status and local alerts; exit 1 means an alert and exit 2 means another job owns the lock. Render schedules with `python3 scripts/render-scheduler.py --config /private/path/operations.json --output var/scheduler-review`. Review paths and permissions before operator installation via launchd or systemd. Rendering itself does not install or start anything. The trigger runs every five minutes; backup eligibility is evaluated at 01:15 Asia/Jakarta with catch-up after downtime. No email/Slack delivery is configured.

For recovery, run `python3 scripts/secure-backup.py unseal ARCHIVE.cms --recipient CERT.pem --key PRIVATE.key --directory EMPTY_PRIVATE_DIRECTORY`, then use the guarded restore procedure above on the resulting `backup.sql.gz`. All authentication and archive validation must pass before restore. See [actual encrypted recovery and capacity evidence](../testing/release-followup-2026-10-07.md).

## Receipt/issue migration and replay recovery

Before enabling the idempotency image, take a fresh full backup and apply `php scripts/migrate-operation-requests.php` from the new image using the same protected DB configuration. The packaged SQL is CREATE TABLE IF NOT EXISTS only; no seed replay. Fresh installations already contain it. The CLI can be repeated; readiness checks the new columns. MySQL DDL is not transactionally rolled back. Full backup/restore must preserve operation_requests together with stock/ledger/source/audit. Restoring an older backup requires the additive migration before readiness; it cannot reconstruct keys for historical unkeyed operations. Do not truncate or expire markers without an explicit replay-retention policy. See ADR-008 and idempotency verification evidence.

## Business-operation schema upgrade

Back up before running scripts/migrate-business-operations.php from the new image. The migration adds five business tables and signed Adjustment ledger support without replaying seed data. It is repeatable; activate the new image only after migration/readiness validation. MySQL DDL commits implicitly, so retain the pre-upgrade backup. Restore old-schema backups into a guarded empty target, apply both operation-request and business-operation migrations, then verify readiness. Full backups must include original ledger rows, operation items, approvals and inventory_return_totals together; never reset return counters separately. Main localhost preservation evidence is docs/testing/business-enhancements-2026-10-07/migration.json.
