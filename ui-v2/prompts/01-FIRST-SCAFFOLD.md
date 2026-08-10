# First Claude prompt — nothing → something

Paste the prompt below into Claude Code from the **Stashd repository root** after dropping this `ui-v2/` folder into the repo.

---

We are starting the Stashd UI-v2 rebuild.

Read the repo's normal agent instructions first, then read:

- `ui-v2/CLAUDE.md`
- `ui-v2/planning/NOW.md`
- `ui-v2/planning/ROADMAP.md`

For this task, work **only inside `ui-v2/`**.

The task is deliberately tiny: take the existing seed project from "it technically boots" to the first useful **application shell**.

Please:

1. Inspect the existing `ui-v2` seed and run/install what you need to verify it.
2. Build a minimal Stashd app shell using Nuxt UI v4 and Vue Router.
3. Give me simple navigation for:
   - Stashes
   - Vault
   - Broadcasts
   - Settings
   - Design playground (this can be visually secondary/dev-ish)
4. Keep each destination as placeholder content only.
5. Make the shell usable at desktop width and not obviously broken at a narrow/mobile width.
6. Use Nuxt UI defaults for now. **Do not theme or brand it yet.**
7. Keep the implementation small and obvious. Do not introduce Pinia, an API layer, auth architecture, backend changes, or a custom design system.
8. Do not modify the legacy/current Stashd UI or PHP backend.
9. Run the relevant typecheck/build when done.

**Hard stopping point:** once I can launch UI v2, see a coherent shell, and click between the placeholder pages, stop. Do not begin the design-system phase and do not start designing the Stashes page.

At the end, tell me briefly what you changed, what command I should run to view it, and anything you deliberately left for the next iteration.

---
