# Release verification follow-up — 2026-10-07

Requirement IDs: TEST-01/02/03, DB-01, ARCH-01/02, UI-01; optional release hardening supporting mandatory behavior.

Files changed: scripts/secure-backup.py, operations-job.py, render-scheduler.py, verify-operations.py, verify-capacity.py; compose.capacity.yaml; deploy/automation/operations.example.json; tests/Operations/test_encryption_job.py; tests/Support/large-dataset.php; tests/HTTP/load.py; .github/workflows/quality.yml; operational documentation and evidence.

Behavior implemented: authenticated encrypted backups and verified replica copies; daily backup decision at 01:15 Asia/Jakarta, five-minute monitoring trigger, single-job locking, retry of pending archives, retention after replica verification, deduplicated local alerts. Scheduler rendering does not install services or create deployment directories. The systemd timer uses monotonic intervals; missed daily backups are caught up by the job after it restarts. CI now includes the capacity drill, pending publication and hosted execution.

Security/authorization: CMS AES-256-GCM with RSA-OAEP SHA256. Scheduler needs only the public recipient certificate. Private directories 0700, archives 0600; wrong keys/tampered ciphertext rejected before plaintext release. Test keys are disposable and never evidence. No external notifications sent; a local replica does not establish off-host protection.

Transaction/invariants: no business workflow modifications. Capacity receipts/issues execute actual services, stock transactions and ledger. Recovery targets only empty, explicitly guarded disposable databases. Main application database is not restored into or mutated by the verification.

Tests added/updated: encryption, tampering, wrong recipient, unsafe tar contents, exclusive publication, job overlap, missing mounts, replica alerts, retry/day scheduling and scheduler path handling.

Commands executed + result:

- `python3 -m unittest discover -s tests/Operations -v`: 35 passed.
- `python3 scripts/verify-operations.py --recovery-only ...`: 9 checks passed, 199 HTTP checks passed; encrypted replica decrypts to byte-identical dump; all 15 tables preserve counts/digests; restore 1.816 seconds; two disposable projects cleaned and private test workspace removed.
- `docker compose -p inventory-quality-followup --profile quality run --build --rm test`: exit 0; PHPUnit 334 tests / 1418 assertions, PHPStan level 5 no errors, 50 JavaScript tests passed. Disposable quality services cleaned.
- `python3 scripts/render-scheduler.py ...` and `plutil -lint ...`: rendering succeeded, plist valid; not installed.
- Earlier capacity drill in this follow-up: 10,000 products and 20,000 orders, 40,000 ledger entries, expected total quantity 120,000, zero ledger balance mismatches. Admin 3,200 reads over 60 seconds: zero failures, p95 60.87 ms. Warehouse 160 reads: zero failures, p95 159.89 ms. Local Docker measurements are not a production SLA.

Docs/evidence updated: [evidence directory](release-followup-2026-10-07/), [operations runbook](../operations/runbook.md), [physical device checklist](../operations/device-acceptance.md).

Known gaps/risks: hosted GitHub CI/branch protection not activated; scheduler templates not installed; production recipient/key custody and off-host storage destination not assigned; outbound alerts not configured; Android Chrome/iPhone Safari physical acceptance remains NOT RUN. No trainer decision inferred. Disposable test certificates must not be used for deployed backup encryption.

Recommended next task: provide deployment/storage/alert ownership, activate the reviewed schedule with a durable operator-owned recipient certificate, execute physical-device acceptance, then enable hosted CI and required branch checks after authorized publication. New audit/idempotency features remain a separate slice.
