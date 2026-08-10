# Claude task template — tiny UI iteration

Use this shape for most UI-v2 sessions.

```text
Read the normal repo instructions plus `ui-v2/CLAUDE.md` and `ui-v2/planning/NOW.md`.

Current task:
<one visual/interaction outcome>

Scope:
- <thing Claude may change>
- <thing Claude may change>

Explicitly do not:
- <adjacent work that is tempting>
- <backend/integration work>
- continue into the next logical slice

Use fixture/local data when needed. If this reveals a missing backend capability, record it in `ui-v2/planning/INTEGRATION-GAPS.md` rather than implementing it.

Done when:
<what I should be able to look at/click/review>

Then stop, run the narrow relevant checks, and report what changed.
```

## Example: first pass of a page

```text
Current task:
Establish only the overall Stashes page layout.

I want to see the page title, primary action, filtering/search area, and the overall shape of the stash list using fixture data.

Do not design the final stash row contents yet. Do not implement empty/loading/error states. Do not touch the backend.

Done when I can look at the populated-page composition and react to its hierarchy, density and layout.
Then stop.
```

## Example: one representative component

```text
Current task:
Design one stash row properly inside the already-approved Stashes layout.

Render all fixture entries using that same row component, but concentrate only on getting the representative row right.

Do not add more page features, new states, API integration, or refactor other approved UI.

Done when I can judge the information hierarchy and actions for a stash row.
Then stop.
```
