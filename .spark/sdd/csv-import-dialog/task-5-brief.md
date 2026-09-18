### Task 5: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: JavaScript suite**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --test tests/JavaScript/*.test.js
```

Expected: `pass 17, fail 0` (unchanged — `dialog.js` has no unit tests, same as `modal.js`).

- [ ] **Step 2: PHP suite and static analysis**

```bash
docker compose exec -T app composer test
docker compose exec -T app composer analyse
```

Expected: PHPUnit all-green (103 tests/423 assertions per the last recorded run, unless a later session changed that count); PHPStan level 5, 0 errors.

- [ ] **Step 3: HTTP smoke test across all six pages that now have an import dialog**

```bash
cd /tmp
for feature in categories customers products suppliers users warehouses; do
  code=$(curl -s -o /dev/null -w "%{http_code}" -b cookies.txt "http://localhost:8081/$feature")
  echo "$feature -> $code"
done
```

Expected: every line prints `200`.

- [ ] **Step 4: Update `ai-usage-log.md`**

Append one row (Date | Tool/Agent | Purpose | Human Review | Verification format, matching every existing row) with `Tool/Agent` = `Claude Code`, summarizing this enhancement (import CSV moved from an always-visible inline form to a dialog behind an "Import CSV" button on six list pages, opening automatically when there's an import error), and listing the real Step 1-3 results.

- [ ] **Step 5: Skip commit** — no git in this repository.
