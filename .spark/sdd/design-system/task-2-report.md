# Task 2 Report: Finish the `#ffffff` → `var(--surface)` token migration

File modified: `public/assets/css/app.css`

## Pre-check

Found exactly 13 non-definition occurrences of `#ffffff` (plus the `--surface` definition at line 3), matching the brief's expectation. No blocking discrepancies — all 13 "Locate" descriptions matched the file content, confirmed by inspecting context around each line before editing.

## All 13 edits (before → after)

1. **Step 1a — `.button-primary, button[type="submit"]` (base rule, line 260)**
   - Before: `    color: #ffffff;`
   - After: `    color: var(--surface);`

2. **Step 1b — `.button-primary:hover, .button-primary:focus, button[type="submit"]:hover, button[type="submit"]:focus` (line 271)**
   - Before: `    color: #ffffff;`
   - After: `    color: var(--surface);`

3. **Step 2 — `.filters` gradient background (line 355)**
   - Before: `    background: linear-gradient(180deg, #ffffff, var(--surface-muted));`
   - After: `    background: linear-gradient(180deg, var(--surface), var(--surface-muted));`

4. **Step 3 — `.metric-card` gradient background (line 376)**
   - Before: `    background: linear-gradient(180deg, #ffffff, #f8fafc);`
   - After: `    background: linear-gradient(180deg, var(--surface), #f8fafc);`
   - Only the first gradient stop changed; `#f8fafc` (second stop) was left untouched per brief instructions.

5. **Step 4 — `.report-panel` gradient background (line 528)**
   - Before: `    background: linear-gradient(180deg, #ffffff, var(--surface-muted));`
   - After: `    background: linear-gradient(180deg, var(--surface), var(--surface-muted));`
   - Confirmed distinct from Step 2 by checking the selector directly above (`.report-panel` vs `.filters`) before editing.

6. **Step 5 — `.pagination` background (line 599)**
   - Before: `    background: #ffffff;`
   - After: `    background: var(--surface);`

7. **Step 6 — `.pagination a, .pagination span[aria-disabled="true"]` background (line 624)**
   - Before: `    background: #ffffff;`
   - After: `    background: var(--surface);`

8. **Step 7 — `.brand-mark` text color (line 679)**
   - Before: `    color: #ffffff;`
   - After: `    color: var(--surface);`

9. **Step 8 — `.table-toolbar` background (line 738)**
   - Before: `    background: #ffffff;`
   - After: `    background: var(--surface);`

10. **Step 9 — `.dashboard-panel, .quick-panel` background (line 806)**
    - Before: `    background: #ffffff;`
    - After: `    background: var(--surface);`

11. **Step 10 — `.page-loading > *, .loading-card` background (line 922)**
    - Before: `    background: #ffffff;`
    - After: `    background: var(--surface);`

12. **Step 11 — `.modal-panel, .confirm-panel` background (line 954)**
    - Before: `    background: #ffffff;`
    - After: `    background: var(--surface);`

13. **Step 12 — `.modal-header` background (line 976)**
    - Before: `    background: #ffffff;`
    - After: `    background: var(--surface);`

No `border-radius` or any other property on any of these selector blocks was touched.

## Step 13: Verification

```
$ grep -c "#ffffff" public/assets/css/app.css
1
$ grep -n "#ffffff" public/assets/css/app.css
3:    --surface: #ffffff;
```

Count is exactly `1`, and the sole remaining line is `app.css:3`, the `--surface` token definition — untouched as required.

## Step 14: Rebuild

```
$ APP_PORT=8081 docker compose up -d --build app
```

Build completed successfully (`tugasakhir-app  Built`), `tugasakhir-app-1` container recreated and started, `tugasakhir-db-1` healthy. A quick `curl` to `http://localhost:8081/dashboard` returned `302` (expected redirect, e.g. to login), confirming the app is serving. Since `var(--surface)` still resolves to `#ffffff` (Task 1 did not touch `--surface`), there is no visual change from this task — only Task 1's separate, expected palette differences remain visible.

## Step 15: Commit

Skipped — no git in this repository, per task instructions.

## Self-review

- `grep -c "#ffffff"` → `1`, and that line is `app.css:3` (`--surface: #ffffff;`). Confirmed.
- Verified via `sed -n` context dumps around every one of the 13 target lines, both before and after editing, that only the `background`/`color` value itself changed on each line — `border-radius`, `box-shadow`, `border`, and every other declaration in the same blocks are untouched.
- The two textually-similar-but-distinct gradient pairs were each verified by selector name before editing:
  - `.filters` (line 355) vs `.report-panel` (line 528) — both `linear-gradient(180deg, #ffffff, var(--surface-muted))` — confirmed via the selector text 6 lines above each occurrence; edited independently, not duplicated or skipped.
  - `.pagination` (line 599, direct `background: #ffffff;`) vs `.pagination a, .pagination span[aria-disabled="true"]` (line 624) — confirmed via context; edited independently.
  - `.button-primary/button[type=submit]` base rule (line 260, `color: #ffffff;`) vs the combined `:hover`/`:focus` rule (line 271) — both are covered by Step 1 in the brief (which explicitly calls out "two separate occurrences... one in the base rule, one in the hover/focus rule") and both were edited.
- No unintended edits: exactly 13 lines changed, matching the 13 non-definition `#ffffff` occurrences found up front.

No concerns.
