# Stashd frontend

The Vue frontend for Stashd.

## Stack

- Vue 3
- Vite
- TypeScript
- Vue Router
- Nuxt UI v4
- Tailwind CSS (through Nuxt UI)

This is a plain Vue/Vite application. **It is not a Nuxt application.** Nuxt UI supports Vue/Vite directly.

## First run

```bash
npm ci
npm run dev
```

Then open the Vite URL shown in the terminal (normally `http://localhost:5173`).

The interface design is recorded in `planning/DECISIONS.md`.

## Important files

```text
planning/NOW.md               current phase + hard stopping point
planning/ROADMAP.md           phased rebuild plan
planning/PAGE-INVENTORY.md    pages/surfaces to work through
planning/STATE-MATRIX.md      states discovered but not necessarily built yet
planning/INTEGRATION-GAPS.md  missing backend/API capabilities discovered by design
planning/DECISIONS.md         durable UI decisions
src/pages/                    route-level UI
```

## Working rhythm

A good development session should usually produce **one thing you can look at
and react to**.

Examples:

- establish the shell, then stop;
- establish the Stashes page layout, then stop;
- design one stash row, then stop;
- add the empty state, then stop;
- tune spacing/typography on the approved layout, then stop.

Avoid prompts such as "build the new frontend" or even "finish the Stashes page" until you genuinely want a large slice.

## Working rules

- Keep production pages backed by the real API; use test-only data only in test sources.
- Treat phone-sized layouts as first-class; check completed slices at desktop
  and approximately 390px wide.
- The current task is the maximum scope; stop at its requested slice.
- Treat approved pages and slices as frozen unless the task explicitly revisits
  them.
- Record backend gaps in `planning/INTEGRATION-GAPS.md`; do not implement them
  prematurely.
