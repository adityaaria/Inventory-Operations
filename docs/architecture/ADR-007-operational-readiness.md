# ADR-007: Native operational readiness without business policy changes

Date: 2026-10-07. Status: engineering decision implemented for user-authorized enhancement priorities 1–6; not trainer business approval.

## Context

Mandatory flow evidence is green. The assessment runtime, manual dependency injection and transaction/ledger boundaries remain authoritative. Production operation requires recoverable data, worker-based HTTP serving, observable failures and repeatable verification. No framework or cloud service is introduced.

## Decision

- Keep local compose.yaml and its assessment seed; add standalone compose.production.yaml with PHP-FPM (no dev/debug/coverage packages), Nginx TLS, secure cookies, debug off, internal DB, read-only app/web root filesystems and persistent session/log volumes.
- Production DB uses an explicitly selected external restored volume. An empty deployment is not auto-seeded with demo accounts. Restore into a fresh inventory-restore-* project/database ending _restore, compare every table, stop the restore DB and promote its volume. Use production credentials when creating that target; disposable defaults are only for verification.
- Native local Python Docker tooling creates consistent InnoDB logical backups using mysqldump --single-transaction, protected files, gzip/manifest digests and retention. Restore never overwrites a populated target or the main project. Restore is disaster recovery, not a stock-domain mutation; restore all related tables together and reconcile before promotion. No concurrent DDL during backup; quiesce business writes for source-vs-restored digest comparison, not for the InnoDB snapshot itself.
- Health probes never start sessions or expose exception/config details; they verify bootstrap schema, critical tables and writable persistent paths. MySQL health uses authenticated TCP SELECT 1 to avoid treating the temporary bootstrap server as ready.
- JSON application logs rotate under a shared process lock; container logs are bounded. HTTP 500s and global exceptions create generic events without request bodies, cookies, SQL exception messages or credentials. Monitoring emits local JSON/exit-code alerts, with no automatic external messaging or unapproved destination.
- CI runs locked dependencies, quality, Docker production builds and isolated recovery/HTTPS/load tests; read-only token, pinned official checkout, no deployment/publishing secrets. GitHub execution requires the workflow to reach the remote; local verification cannot prove hosted execution.
- Benchmark authenticated independent sessions with bounded users and localhost disposable-project guards. Report measurements/hardware limits; concurrency integrity already has separate stock integration tests. Physical Android/iPhone execution remains a user-run acceptance gate.
- Pending business policies are recommendations only in DECISIONS_PENDING.md; current states, authorization and stock rules remain unchanged.

## Tradeoffs and remaining operational boundaries

Single-host Docker/native sessions remain the intended topology. Off-host encrypted backup copies, public CA certificates/renewal, scheduler ownership/alert delivery, production RPO/RTO acceptance and device/trainer sign-off depend on the deployment environment. Do not infer successful external setup from these local artifacts. Bound log retention limits storage but may remove old debug events; transactional audit remains in MySQL backups. Large-row fingerprint verification is an operator drill, not a web request or continuous full-table poll.

## Sources consulted

[MySQL 8 mysqldump](https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html), [PHP-FPM configuration](https://www.php.net/manual/en/install.fpm.configuration.php), [Nginx TLS](https://nginx.org/en/docs/http/ngx_http_ssl_module.html), [GitHub Actions secure use](https://docs.github.com/en/actions/reference/security/secure-use).
