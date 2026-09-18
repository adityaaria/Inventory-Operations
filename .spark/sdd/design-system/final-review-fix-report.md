# Design System Fase 1 — Final Review Fix Report

**Date:** 2026-09-11  
**Task:** Add five orphaned hardcoded palette residues to known-gaps doc  
**Status:** COMPLETED

## Verification — grep outputs (all values confirmed present)

### 1. Global focus ring — hardcoded blue outline
```
130:    outline: 3px solid rgba(29, 78, 216, 0.28);
339:    box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.1);
```
✓ Confirmed at line 130

### 2. Button drop shadows — hardcoded blue-tinted box-shadow
```
62:        radial-gradient(circle at 16% 0%, rgba(37, 99, 235, 0.14), transparent 22rem),
222:    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.10);
253:    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.10);
262:    box-shadow: 0 10px 22px rgba(37, 99, 235, 0.18);
574:        radial-gradient(circle at 15% 15%, rgba(37, 99, 235, 0.18), transparent 22rem),
```
✓ Confirmed at lines 222, 253, 262

### 3. Loading spinner track border — hardcoded pale blue
```
932:    border: 2px solid #bfdbfe;
```
✓ Confirmed at line 932

### 4. Page background radial gradients — hardcoded old blue & old teal
```
Blue (rgba(37, 99, 235, ...)):
62:        radial-gradient(circle at 16% 0%, rgba(37, 99, 235, 0.14), transparent 22rem),
574:        radial-gradient(circle at 15% 15%, rgba(37, 99, 235, 0.18), transparent 22rem),

Teal (rgba(15, 118, 110, ...)):
63:        radial-gradient(circle at 86% 4%, rgba(15, 118, 110, 0.12), transparent 20rem),
161:    border: 1px solid rgba(15, 118, 110, 0.2);
575:        radial-gradient(circle at 88% 18%, rgba(15, 118, 110, 0.14), transparent 20rem),
```
✓ Confirmed at lines 62-63 and 574-575

## New bullets added to `docs/quality/design-system-fase-1-known-gaps.md`

Under "## Visible mismatches introduced by this phase" section, after the existing three bullets:

1. **Global focus ring** (`app.css:130`) — hardcoded blue `outline: 3px solid rgba(29, 78, 216, 0.28)` is not derived from any token; every focused control still rings blue against the new near-black `--primary` palette.

2. **Button hover/focus drop shadows** (`app.css:222`, `:253`, `:262`) — hardcoded blue-tinted `box-shadow` values (`rgba(37, 99, 235, ...)`) now sit under buttons whose background is near-black `#16181D`, creating stark contrast mismatches.

3. **Loading spinner track border** (`app.css:932`) — hardcoded pale blue `border: 2px solid #bfdbfe` clashes with the new palette.

4. **Page background radial gradients** (`app.css:62-63`, `:574-575`) — hardcoded old blue (`rgba(37, 99, 235, ...)`) and old teal (`rgba(15, 118, 110, ...)`) tints remain in the background gradients on every page, still presenting old-palette interference.

## Notes

- No wording about "focus rings will update" was found in the doc — the minor reporting note adjustment was not needed.
- All five values verified as currently present in `public/assets/css/app.css` via targeted grep.
- No other files modified; documentation-only fix.
- No git operations performed (no git repo in this directory).
