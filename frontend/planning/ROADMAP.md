# Frontend roadmap

This roadmap describes phases, not autonomous tasks. Do not advance phases without being asked.

## Phase 1 — Scaffold

- Vue/Vite/Nuxt UI boots independently.
- Router works.
- Minimal Stashd application shell exists.
- Placeholder routes exist.
- No meaningful brand design yet.

## Phase 2 — Design language

Work primarily in `/design` until the core Stashd visual vocabulary feels right.

Explore and settle:

- typography hierarchy
- page density and spacing
- semantic colors
- surfaces/backgrounds/borders
- buttons and actions
- inputs and forms
- badges/status treatments
- navigation
- cards/list rows
- tables
- thumbnails/artwork treatment
- empty states
- loading/skeleton patterns
- warnings/errors
- modal/drawer conventions

The design playground is a tool, not a permanent obligation. Do not turn it into exhaustive Storybook replacement.

## Phase 3 — Application shell

Finalize:

- sidebar/navigation
- page container behavior
- headers/toolbars
- responsive shell behavior
- global command/search affordances only if actually desired

## Phase 4 — Pages, one slice at a time

Build each page iteratively with fixtures/local state.

Typical order inside a page:

1. page layout
2. primary content structure
3. one representative item/component
4. controls
5. explicit states
6. polish
7. visual approval/freeze

Do not assume the exact page order until Hazel chooses it.

## Phase 5 — Cross-page consistency

Only after pages are individually approved:

- reconcile inconsistent spacing/typography
- consolidate genuinely repeated patterns
- accessibility pass
- responsive consistency
- remove obvious one-off hacks

Do not redesign approved pages wholesale.

## Phase 6 — Integration inventory

For every fixture/local action:

- map it to an existing backend capability;
- identify missing fields/endpoints/actions;
- decide the minimum backend changes required;
- prioritize gaps.

This is where `planning/INTEGRATION-GAPS.md` becomes actionable.

## Phase 7 — Backend integration

Replace fixtures with real data, page-by-page.

Keep already-approved visual behavior stable unless real constraints force a change.

## Phase 8 — Replacement

- production integration/build path
- auth/session behavior finalized
- migration/cutover
- remove or archive legacy UI only when v2 is actually ready
