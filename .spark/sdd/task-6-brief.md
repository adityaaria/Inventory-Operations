### Task 6: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: JavaScript suite**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --test tests/JavaScript/*.test.js
```

Expected: `tests 17`, `pass 17`, `fail 0`.

- [ ] **Step 2: PHP suite and static analysis**

```bash
docker compose exec -T app composer test
docker compose exec -T app composer analyse
```

Expected: PHPUnit all-green (this plan touched only PHP *view* markup, not `app/`, so the existing test count should be unchanged from the last recorded run — 103 tests/423 assertions per `ai-usage-log.md`'s 2026-09-07 entry, unless later sessions added more); PHPStan level 5, 0 errors.

- [ ] **Step 3: HTTP smoke test across all six master-data create/edit pairs**

```bash
cd /tmp
for feature in categories customers products suppliers users warehouses; do
  code=$(curl -s -o /dev/null -w "%{http_code}" -b cookies.txt "http://localhost:8081/$feature/create")
  echo "$feature/create -> $code"
done
```

Expected: every line prints `200`.

- [ ] **Step 4: Update `ai-usage-log.md`**

Append one row documenting this session's enhancement, following the existing table format in `ai-usage-log.md` (Date | Tool/Agent | Purpose | Human Review | Verification), with `Tool/Agent` = `Claude Code`, and `Verification` listing the exact commands and results from Steps 1–3 above.

- [ ] **Step 5: Final commit**

```bash
git add ai-usage-log.md
git commit -m "docs: log UI polish enhancement in AI usage log"
```
