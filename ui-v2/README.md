# Stashd UI v2

A deliberately isolated frontend rebuild for Stashd.

## Stack

- Vue 3
- Vite
- TypeScript
- Vue Router
- Nuxt UI v4
- Tailwind CSS (through Nuxt UI)

This is a plain Vue/Vite application. **It is not a Nuxt application.** Nuxt UI supports Vue/Vite directly.

## Why this exists separately

The current Stashd UI stays intact while UI v2 is designed page-by-page.

The rebuild is intentionally split into two large concerns:

1. **Design the whole interface with fixtures/local state.**
2. **Only then integrate it with the real Stashd backend and replace the legacy UI.**

That keeps visual iteration fast and prevents backend/API work from swallowing the redesign.

## First run

```bash
cd ui-v2
npm install
npm run dev
```

Then open the Vite URL shown in the terminal (normally `http://localhost:5173`).

The seed project intentionally contains almost no design. The first Claude session should create only the bare application shell. See:

```text
prompts/01-FIRST-SCAFFOLD.md
```

## Important files

```text
planning/NOW.md               current phase + hard stopping point
planning/ROADMAP.md           phased rebuild plan
planning/PAGE-INVENTORY.md    pages/surfaces to work through
planning/STATE-MATRIX.md      states discovered but not necessarily built yet
planning/INTEGRATION-GAPS.md  missing backend/API capabilities discovered by design
planning/DECISIONS.md         durable UI decisions
src/fixtures/                 page-boundary fixture data
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

## Page-boundary fixtures

Fixtures are intentionally simple. A page may import from `src/fixtures/` while being designed.

Do not add a mock HTTP service merely to make the fake data feel more realistic. Integration is a later phase.
