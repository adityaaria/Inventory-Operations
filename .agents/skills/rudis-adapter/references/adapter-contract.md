# Rudis Adapter Contract

## Inputs

Default marker:

```json
{
  "enabled": true,
  "mode": "read-only",
  "constitution": ".rudis/memory/constitution.md",
  "specs": "specs",
  "adapter": ".agents/skills/rudis-adapter"
}
```

Read only files that exist. Missing files are gaps, not failures.

Primary inputs:

- `.rudis/memory/constitution.md` - project principles and governance constraints
- `specs/<feature>/spec.md` - feature requirements and business flow context
- `specs/<feature>/plan.md` - technical intent, architecture decisions, constraints
- `specs/<feature>/tasks.md` - implementation task context
- `specs/<feature>/research.md` - design decision support
- `specs/<feature>/data-model.md` - domain/entity model support
- `specs/<feature>/quickstart.md` - verification and usage scenarios

## Output Shape

Return a concise neutral block titled **External Knowledge Adapter Context**:

```markdown
## External Knowledge Adapter Context

Source: Rudis
Mode: read-only
Marker: .spark/integrations/rudis.json

### Project Principles
- [principle or constraint] (source: .rudis/memory/constitution.md)

### Feature Context
- [feature requirement or flow] (source: specs/<feature>/spec.md)

### Technical Intent
- [architecture or implementation constraint] (source: specs/<feature>/plan.md)

### Task Context
- [task guidance, if relevant] (source: specs/<feature>/tasks.md)

### Gaps
- [missing artifact, conflict, or unknown]
```

When `specs/<feature>/tasks.md` is present and relevant, append a bounded task-normalization block titled **Rudis Task Intake**. This block turns Rudis' high-level, dependency-ordered checklist into neutral context for SPARK planning. It is not a SPARK implementation plan.

```markdown
## Rudis Task Intake

Source: specs/<feature>/tasks.md
Mode: read-only

### Feature Directory
- [absolute or project-relative feature directory]

### Task Inventory
| TaskID | Phase | Parallel | Story | File Paths | Summary |
| ------ | ----- | -------- | ----- | ---------- | ------- |
| T001 | Setup | no | none | src/... | [task intent] |

### Story Slices
- US1: [goal / independent test criteria if stated] (tasks: T010, T011)

### Execution Signals
- Sequential prerequisites: [setup/foundation/blocking tasks]
- Parallel candidates: [task IDs marked [P], grouped only when file paths do not overlap]
- Test-first signals: [test tasks or notes that tests must fail before implementation]
- Checkpoints: [story or phase validation points]

### SPARK Planning Handoff
- Use this as task intent for `writing-plans`, not as an executable plan.
- Expand each Rudis task into SPARK-ready implementation steps with files to read first, exact edits, expected tests, verification commands, and review gates.
- Preserve Rudis TaskID references in the generated SPARK plan for traceability.

### Task Gaps
- [task without file path, ambiguous dependency, missing test criterion, or conflict with source code]
```

Extract task fields from Rudis checklist lines. A valid Rudis task usually contains `TaskID`, optional `Parallel` marker `[P]`, optional `Story` marker such as `[US1]`, and `File Paths` embedded in the description. If a field is missing, record it under `Task Gaps` instead of inventing it.

### Example Rudis tasks input

```markdown
## Phase 3: User Story 1 - Create invoice draft (Priority: P1)

**Goal**: Finance user can create an invoice draft.

**Independent Test**: Submit valid invoice details and see a draft invoice in the list.

- [ ] T010 [P] [US1] Add invoice draft validation test in tests/invoice/create-draft.test.ts
- [ ] T011 [US1] Implement invoice draft service in src/invoice/invoice.service.ts
- [ ] T012 [US1] Wire create draft endpoint in src/invoice/invoice.controller.ts
```

### Example Rudis Task Intake output

```markdown
## Rudis Task Intake

Source: specs/001-invoice/tasks.md
Mode: read-only

### Task Inventory
| TaskID | Phase | Parallel | Story | File Paths | Summary |
| ------ | ----- | -------- | ----- | ---------- | ------- |
| T010 | User Story 1 - Create invoice draft | yes | US1 | tests/invoice/create-draft.test.ts | Add invoice draft validation test |
| T011 | User Story 1 - Create invoice draft | no | US1 | src/invoice/invoice.service.ts | Implement invoice draft service |
| T012 | User Story 1 - Create invoice draft | no | US1 | src/invoice/invoice.controller.ts | Wire create draft endpoint |

### Story Slices
- US1: Finance user can create an invoice draft. Independent test: submit valid invoice details and see a draft invoice in the list. (tasks: T010, T011, T012)

### Execution Signals
- Sequential prerequisites: T011 before T012
- Parallel candidates: T010 only; no grouped implementation parallelism because service and controller interact
- Test-first signals: T010 is a test task and should fail before T011/T012 implementation
- Checkpoints: Validate US1 independently after T012

### SPARK Planning Handoff
- Expand T010-T012 into SPARK-ready tasks with files to read first, exact test code, implementation contracts, verification commands, and review gates.
- Preserve source task IDs T010-T012 in the generated SPARK plan for traceability.
```

## Skill Handoff

- `project-scanner`: use the context as evidence. Source code and runtime config still override Rudis artifacts. Write only SPARK's exact `.docs/` contract.
- `knowledge-rules`: treat project principles as candidate rules. Present each candidate for developer approval before writing `docs/spark/rules/KNOWLEDGE_RULES.md`.
- `template-generator`: use feature context and technical intent as supporting evidence only after `.docs/` and approved rules are loaded.
- `project-onboarding`: use feature context as target-domain input only when the template already permits that mapping.
- `writing-plans`: use `Rudis Task Intake` as a traceability scaffold. Keep SPARK's normal plan contract: bite-sized tasks, exact file paths, code/test intent, verification steps, TDD ordering, review gates, and YAGNI/DRY constraints.

## Boundaries

- Do not load this adapter for users who did not enable or request it.
- Do not make any SPARK core skill mention Rudis-specific paths.
- Do not persist adapter output as a standalone permanent memory file.
- Do not treat task lists as stable architecture memory.
- Do not overwrite SPARK-generated memory with external documents.
- Do not execute Rudis tasks directly. Normalize them and hand them to SPARK planning.
