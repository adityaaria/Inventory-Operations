# Typography & Palette Design System Enhancement

## Status

Approved design checkpoint for the fourth design-system enhancement batch.

## Goal

Centralize typography and active palette values so the interface has a more predictable visual hierarchy and fewer legacy blue/teal mismatches, without changing markup, routes, authorization, business behavior, or persistence.

The visual direction remains **Operational clarity**: readable hierarchy, restrained color use, and semantic status feedback.

## Scope

Included components:

- typography size tokens;
- typography weight tokens;
- page headings and subtitles;
- body, navigation, button, table, metadata, and metric typography;
- active palette aliases for primary, accent, surface tint, shadows, and focus;
- identified legacy background gradients and hardcoded palette literals.

Target runtime file:

- `public/assets/css/app.css`.

Target test/evidence files:

- `tests/JavaScript/design-system.test.js`;
- `docs/quality/design-system-fase-1-known-gaps.md`.

## Constraints

- Keep PHP 8.2+, server-rendered HTML, custom CSS, and Vanilla JS.
- Do not add a CSS framework, frontend dependency, or runtime dependency.
- Do not change markup, JavaScript behavior, routes, authorization, CSRF, services, repositories, queries, or database behavior.
- Preserve the semantic meaning of success, warning, danger, info, draft, and operational status colors.
- Keep visual values close to the existing interface unless a legacy mismatch is explicitly in scope.
- Maintain visible focus contrast and reduced-motion behavior.

## Shared Component Contract

### Typography tokens

Add a compact token scale:

- `--fs-xs` for badges, metadata, helper text, and compact labels;
- `--fs-sm` for controls, table text, and navigation;
- `--fs-md` for body text;
- `--fs-lg` for subtitles and small section headings;
- `--fs-xl` for page headings;
- `--fs-2xl` for metric and prominent numeric values.

Add weight tokens:

- `--fw-regular`;
- `--fw-medium`;
- `--fw-semibold`;
- `--fw-bold`.

The first migration covers `body`, headings, `.app-title`, `.page-subtitle`, buttons, navigation, metadata, data tables, and metric values.

### Palette tokens

Use semantic tokens for active palette surfaces and effects:

- primary and primary hover;
- accent and accent soft;
- surface tint;
- focus ring;
- shadow and soft shadow;
- page background tint.

Legacy blue/teal gradients and hardcoded `.app-title` teal border are migrated to active palette aliases. Status colors remain semantically distinct.

## State and Accessibility Rules

- Typography changes must not remove visible focus indicators.
- Color must not be the only signal for status or active navigation.
- Status colors must retain sufficient distinction between success, warning, danger, info, and draft.
- Focus ring remains derived from the focus token.
- Reduced-motion behavior remains unchanged.
- No color migration may weaken invalid/error feedback.

## Implementation Sequence

1. Add CSS contract tests for typography and palette tokens.
2. Add the token scale next to existing root variables.
3. Migrate primary typography selectors to size and weight tokens.
4. Migrate the identified legacy palette literals and gradients.
5. Run JavaScript tests and inspect the final CSS diff for unintended selector changes.
6. Run the PHP/Docker regression suite and syntax checks.
7. Update quality evidence with completed scope and intentionally deferred literals.

## Testing and Verification

Automated checks:

- `node --test tests/JavaScript/*.test.js`;
- `composer test:unit` in Docker;
- `composer test:integration` in Docker;
- `composer analyse` in Docker;
- PHP syntax check for all views;
- `git diff --check`.

Manual smoke scope:

- page heading, subtitle, navigation, buttons, tables, and metric values;
- status and error colors;
- focus ring visibility;
- dashboard and login page background treatment.

If browser automation is unavailable, the limitation is recorded instead of fabricating visual evidence.

## Non-goals

- No component markup redesign.
- No status vocabulary or status meaning changes.
- No route, permission, authorization, API, business-flow, repository, service, or database changes.
- No complete palette rewrite beyond identified legacy mismatches.
- No new dropdown, toggle, or bulk-action component.

## Gaps / Unknowns

- Exact visual contrast still needs manual browser review because browser-level visual regression is not configured.
- Some isolated legacy color literals may remain where replacing them would require a separate component decision.
- Font family remains unchanged; this batch only centralizes size and weight.

## Evidence

- `.docs/PROJECT_PROFILE.md`;
- `.docs/FEATURE_MAP.md`;
- `public/assets/css/app.css`;
- `tests/JavaScript/design-system.test.js`;
- `docs/quality/design-system-fase-1-known-gaps.md`.
