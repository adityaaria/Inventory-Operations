---
name: rudis-adapter
description: Use when a project has explicitly enabled the optional Rudis integration, when the user asks to continue Rudis tasks with SPARK, or when the user says "Continue my Rudis tasks with SPARK", "Lanjutkan task Rudis dengan SPARK", "Use SPARK for Rudis tasks", or "Turn Rudis tasks into a SPARK plan". Also use when SPARK should consume Rudis artifacts such as .rudis, specs/<feature>/tasks.md, spec.md, plan.md, or constitution.md as external knowledge.
---

# Rudis Adapter

## Overview

Use this skill to read Rudis artifacts as optional external knowledge for SPARK. This adapter is read-only and opt-in. It does not replace SPARK `.docs/`, `KNOWLEDGE_RULES.md`, `.template/`, or project onboarding contracts.

**Announce at start:**

> spark integration Using rudis-adapter to load optional Rudis knowledge

## Required References

Before consuming Rudis artifacts, you MUST read:

- MUST read `references/adapter-contract.md`.

## Activation Gate

Use this adapter only when at least one condition is true:

- the user explicitly asks for Rudis integration or Rudis artifact consumption
- the project contains `.spark/integrations/rudis.json` with `"enabled": true`

If neither condition is true, do not inspect `.rudis/` or `specs/` for Rudis-specific meaning.

## Core Contract

Always:

- treat Rudis artifacts as external input evidence, not SPARK source of truth
- keep consumption read-only
- load only bounded, relevant files
- normalize findings into neutral SPARK context before handing them to another skill
- convert `specs/<feature>/tasks.md` into a bounded **Rudis Task Intake** when the user's request is about continuing, planning, or implementing Rudis tasks
- hand **Rudis Task Intake** to `writing-plans` when SPARK needs to turn Rudis task intent into a detailed implementation plan
- preserve SPARK approval gates, especially for `knowledge-rules`

Never:

- require Rudis for SPARK core workflows
- copy Rudis documents verbatim into `.docs/`
- create `KNOWLEDGE_RULES.md` entries without developer approval
- run Rudis commands as part of this adapter
- execute Rudis `tasks.md` directly as a SPARK plan
- infer project structure from Rudis plans when source code contradicts it

## Checklist

- [ ] Confirm explicit activation through user request or `.spark/integrations/rudis.json`.
- [ ] Read `references/adapter-contract.md`.
- [ ] Read configured Rudis artifacts in bounded scope.
- [ ] Produce External Knowledge Adapter Context.
- [ ] Produce Rudis Task Intake when `tasks.md` is relevant.
- [ ] Hand the neutral context to the requested SPARK skill.
