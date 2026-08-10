# Stashd UI v2 — Claude working rules

This file adds **UI-v2-specific constraints** to the repository's root `AGENTS.md` and `CLAUDE.md`. The root instructions still apply.

## What this folder is

`ui-v2/` is an isolated rebuild of Stashd's frontend using:

- Vue 3
- Vite
- TypeScript
- Vue Router
- Nuxt UI v4 (Nuxt framework is **not** used)

The existing/legacy Stashd UI remains the production UI until the final replacement phase.

## The core working rule

> **The current task is the maximum scope, not the minimum scope.**

Implement only the requested slice. Do not continue into the next logical task.

A small, reviewable, visually complete change is better than a broad implementation.

## Current strategy

We are deliberately separating **interface design** from **backend integration**.

During the UI phase, answer:

> Does this look right and behave right?

Later, during integration, answer:

> Where does the data come from and what backend capability is missing?

Do not collapse those phases together.

## Hard boundaries during UI phases

Unless the current task explicitly says otherwise:

- Do not modify the existing production UI.
- Do not modify Stashd's PHP backend.
- Do not add or change API endpoints.
- Do not redesign backend data models around speculative UI needs.
- Do not create a fake backend, mock server, repository layer, or elaborate data-access abstraction.
- Do not add Pinia or another global state library merely because it might be useful later.
- Do not solve authentication/integration concerns early.
- Do not perform broad refactors outside `ui-v2/`.
- Do not "finish" adjacent pages while working on one page.
- Do not revisit visually approved pages unless the current task explicitly asks for it.

If the desired UI needs data that does not exist yet, use a fixture and record the gap for the later integration phase.

## Phone support is non-negotiable.

Stashd must be fully functional and pleasant to use on a normal phone-sized viewport. Responsive behaviour is part of completing a UI slice, not a later cleanup phase.

When a UI area is close to visually complete, verify it at both desktop and approximately 390px phone width.

Do not merely shrink desktop layouts. Choose an intentional mobile presentation when necessary.

In particular:

- no hover-only actions
- maintain comfortable touch targets
- avoid requiring horizontal scrolling for primary workflows
- tables may become purpose-designed rows/cards on narrow screens
- keep important actions reachable
- make copyable technical values easy to copy without manual text selection
- use drawers/sheets/menus appropriately for constrained space
- preserve all core functionality unless the product explicitly decides otherwise

## Fixture rule

Prefer fixture data at the page boundary:

```text
src/fixtures/stashes.ts
        ↓
    StashesPage
        ↓
 presentational UI
```

Later this can become:

```text
Stashd API
    ↓
useStashes()
    ↓
StashesPage
    ↓
presentational UI
```

The visual components should not care where the data came from.

## Component rule

- Prefer Nuxt UI components before building generic primitives.
- Custom components are welcome when they express a real Stashd concept or Nuxt UI does not fit cleanly.
- Do not prematurely extract components after seeing something once.
- Extract when repetition or a real product concept makes the boundary obvious.
- Do not build a Stashd component library as a side quest.
- Avoid giant all-purpose components.

## Styling rule

- Phase 1 uses Nuxt UI defaults. Do not invent the brand while scaffolding.
- Phase 2 establishes the visual language in `/design` first.
- Once the design system is established, pages should use that language rather than ad-hoc styling.
- Keep global CSS intentional and small. Do not accumulate page-specific override soup in `main.css`.

The product should feel like quietly competent homelab infrastructure: calm, dense, understandable, private, and reliable.

## Iteration rule

Build pages in slices. A typical sequence is:

1. overall layout
2. primary content structure
3. one important row/card/item
4. controls and local interactions
5. explicit UI states
6. polish
7. user declares the page visually complete

Do **not** treat "build the Stashes page" as permission to complete all seven steps at once unless explicitly asked.

## UI states

Before a page is considered complete, its relevant states should eventually be accounted for (for example: populated, empty, loading, error, in-progress, failed).

But identifying a state does not mean you should implement it immediately.

Record future states in `planning/STATE-MATRIX.md` rather than opportunistically expanding the current task.

## Backend gaps

When a UI design implies missing backend data or behavior:

1. Do not solve it now.
2. Add a short item to `planning/INTEGRATION-GAPS.md`.
3. Continue using fixture/local data if the current UI task can proceed.

## Completed-page rule

When Hazel says a page or slice is approved/finished, treat it as frozen.

Cross-page consistency fixes are allowed later during the explicit consistency phase. Otherwise, do not casually "improve" approved UI while working elsewhere.

## Definition of done for a UI task

The task is done when:

- the specifically requested visual/interaction slice is implemented;
- fixture/local state is sufficient for reviewing it;
- typecheck/build pass when relevant;
- unrelated areas have not been changed; and
- you stop and report what changed.
- a visually completed slice has been reviewed at desktop and phone width.

**Do not continue into the next logical task.**

## Before editing

For non-trivial UI work:

1. Read the root `AGENTS.md` and required root `.claude/rules/` files.
2. Read this file.
3. Read `planning/NOW.md`.
4. Check `git status --short`.
5. Inspect only the relevant UI-v2 files.
6. State a narrow plan with a clear stopping point.

Avoid broad repo scans for a local UI task.

## After editing

Run the narrowest useful checks, normally:

```bash
cd ui-v2
npm run typecheck
npm run build
```

If the task is purely planning/documentation, do not run irrelevant builds.

Then report:

- files changed;
- what is now reviewable;
- fixture/local behavior used;
- any integration gaps recorded;
- what you deliberately did **not** do.
