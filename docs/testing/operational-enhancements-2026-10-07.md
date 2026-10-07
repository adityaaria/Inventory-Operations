# Operational priorities 1–6: implementation and evidence

Requirement IDs: DB-01, AUTH-01/02, ERR-01, ARCH-01/02, TEST-01/02/03; release readiness. Existing PO-01/SO-01 stock/authorization invariants remain covered by the full suite.
Mandatory/Optional: user-authorized operational enhancements; the brief does not make CI/cloud/new business statuses mandatory. Native PHP/MySQL/custom UI stack is preserved.

## Delivered scope

| Priority | Implementation | Verification / boundary |
|---|---|---|
| 1 Backup/restore | Protected compressed InnoDB logical backup, manifest SHA256, gzip integrity, valid-backup retention and empty disposable restore guard; recovery volume promotion runbook | Real backup/restore matches all 15 tables / 309 rows, including source orders, stock, ledger and audit. Main application private backup created in ignored var/backups. Off-host encrypted copying and scheduled execution are operator setup, not claimed installed. |
| 2 Production | Separate Nginx TLS + PHP-FPM image without dev/debug/coverage packages; secure cookies/debug off, internal DB, persistent sessions/logs, read-only app/web filesystems and selected external recovered DB volume | Images built; local trusted TLS, cookies/security headers, internal endpoints, PHP/dotfile restrictions, no DB published port and runtime package exclusions passed. No public deployment or public-CA certificate is claimed. Local assessment stays on localhost:8080. |
| 3 CI | GitHub Actions workflow: Python safety, PHPUnit/MySQL/PHPStan/JS, runtime/production build, HTTP/recovery/HTTPS/load; immutable official action SHAs, read-only repository token, evidence-only artifact uploads, cleanup | Workflow YAML parsed and every underlying command executed locally. Hosted workflow has not run; branch protection is not configured by this task. Remote publication still requires the outstanding destination/payload approval from the earlier push task. |
| 4 Monitoring | Session-free/read-only readiness; authenticated TCP MySQL health; locked application log rotation, bounded Docker logs; generic error events and local JSON/exit-code monitor | Healthy baseline, forced application error alert, database-loss alert and recovered readiness passed. Main monitor healthy. No external alert destination or host scheduler installed. |
| 5 Performance/devices | Bounded localhost/disposable-project load tool, independent authenticated sessions, burst and 60-second pacing; Android Chrome/iPhone Safari checklist | 320 burst reads and 3200 sustained reads pass. User has both device families; no actual physical observations supplied, so checklist remains NOT RUN. Previous 303 Chrome-emulated page checks/110 dialog checks remain historical UI evidence, not device acceptance. |
| 6 Documentation/policy | ADR-007, runbook/cron template, updated architecture/SDD/requirement/debt/release notes; deduplicated current business-decision register | Stale transaction-ownership/no-Git claims corrected. Insight supplied; no trainer-approved rule is invented and current statuses/stock behavior remain unchanged. |

## Commands executed + results

- Standard `docker compose --profile quality run --build --rm test`: **334 tests / 1418 assertions**, PHPStan level 5 no errors, **50 JavaScript tests** passed. This supersedes the earlier task's failed standard-build attempt; old DNS failure remains historical evidence. Current builds may reuse cached layers; a no-cache internet-only dependency rebuild is not claimed.
- `python3 -m unittest discover -s tests/Operations -v`: **19 passed**, including restore main/nonempty rejection, archive integrity/corruption, stale/missing backups, prune dry-run/failure safety, load-target restrictions, dump failure/collision preservation.
- Production `docker compose -f compose.production.yaml build` with disposable environment substitutions: passed; genuine build output retained.
- `python3 scripts/verify-operations.py --recovery-only ...`: recovery slice **7 checks** passed.
- Full isolated verification: **23 checks** passed, including **199/199 real business HTTP checks** before backup, round-trip table digests, TLS/runtime restrictions, monitor injection and both load scenarios. **Three disposable projects cleaned successfully**, recorded in final/cleanup.json.
- Local archive created with `scripts/db-operations.py ... backup`: successful, archive 0600/directory 0700; full dump/TLS/private files are ignored and not published in evidence.
- Main runtime recreated without DB reseed; **readiness ready, 16 session checks passed, monitoring healthy, 164 PHP syntax checks passed**. Main app/database remain running; disposable test-db removed.
- Python compilation, workflow YAML parsing, all three Compose `config --quiet`, `git diff --check`: passed.

Tests added/updated: three LogRetention unit cases and two real MySQL health/fingerprint integration cases (334 total vs 329 previous); existing JsonFileLogger exception test now verifies omission of raw exception messages. Python operational safety cases and guarded real orchestration/load drivers added. The standard quality gate still covers four actual parallel stock/catalog scenarios; load measurements are authenticated reads, not a replacement for mutation-integrity tests.

## Measured load / recovery

Local Docker Desktop aarch64, 8 CPUs and 4,106,604,544 bytes configured memory; small seeded+HTTP fixture dataset of 309 table rows, not production scale.

| Scenario | Requests / sessions | Elapsed | p50 | p95 | Max | Failures |
|---|---|---|---|---|---|---|
| Burst | 320 / 8 | 1.243s | 11.03ms | 21.76ms | 419.28ms | 0 |
| Paced 60-second load | 3200 / 8 | 60.383s | 13.24ms | 25.77ms | 245.08ms | 0 |

Initial acceptance p95 budget: 2000ms; both passed. Latency excludes login; elapsed includes it. Paced throughput: 52.99 reads/sec. Restore/import plus fingerprint check took 0.866s for this tiny fixture; this is not a promised RTO. No representative large-data benchmark, hours-long soak, multi-host capacity or production SLA claim.

## Business insight requested by the user

- Partial PO receipt: retain received goods and the original ledger. Keep existing cancellation rejection; a future close-remainder workflow must be agreed explicitly. A cancellation must never silently subtract already received goods.
- Admin self-approval: a second approver gives stronger separation, but teams with one Admin need an agreed operational policy. Current self-approval remains permitted until trainer confirmation.
- Low-stock: prefer per-warehouse alerting; another warehouse's stock is not immediately available locally. Current count is active product/warehouse pairs with quantity < product reorder_point; do not relabel it as distinct products or aggregate thresholds without agreement.

See DECISIONS_PENDING.md for all open rows, current defaults and confirmation process.

Files changed: Dockerfile/local Compose health and new production/restore Compose; deploy PHP/Nginx/env/cron templates; workflow; OperationalHealth repository contract/MySQL implementation; LogRetention/JsonFileLogger and HTTP500 recording; health/fingerprint/log-summary/backup/monitor/verification scripts; load and safety/unit/integration tests; requirement/architecture/planning/runbook/evidence docs. User-added Guidelines PDF was left untouched.
Security/authorization: server role/ownership/CSRF/session behavior unchanged; production cookie TLS and private DB endpoint verified. Health outputs no config, DB credentials or exception details. Backups never emitted as Git/CI artifacts; protected local files only. No outbound alert messages or credential commit.
Transaction/invariants: health/fingerprint/backup read only; recovery restores all related tables into a new guarded target and promotes only after verification. No balance/ledger mutation bypass; no new business statuses or trainer policies.
Docs/evidence updated: ADR-007, operations runbook, physical checklist, SDD, KNOWLEDGE, README, architecture, decisions/debt/release notes and ai-usage-log. Raw verification in [operations-2026-10-07](operations-2026-10-07/); final/ is current, earlier recovery/full slices retained chronologically.
Known gaps/risks: hosted CI/public deployment, public certificate renewal, off-host encrypted backup, scheduler/alert ownership, actual Android/iPhone results, trainer answers, and representative production capacity require environment/human inputs. Do not mark those closed from local tests.
Recommended next task: select deployment host/domain and operational owners, execute physical-device checklist, and obtain trainer decisions before changing business rules.
