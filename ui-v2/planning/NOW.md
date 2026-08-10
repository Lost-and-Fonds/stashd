# NOW

## Current phase

**Phase 1 — Nothing → something scaffold**

## Goal

Produce the smallest useful navigable Stashd UI-v2 shell so Hazel has something visible to react to.

## Allowed in this phase

- basic app shell
- basic navigation
- placeholder route content
- Nuxt UI defaults
- enough responsive behavior that the shell is not obviously broken
- build/typecheck fixes inside `ui-v2/`

## Explicitly not in this phase

- Stashd branding/theme work
- detailed design-system work
- detailed Stashes/Vault/Broadcasts/Settings UI
- backend/API integration
- auth integration
- global state architecture
- legacy UI changes
- production build/deployment integration with the PHP app

## Stop condition

Stop when the app boots, navigation works between the placeholder routes, the shell is visible and coherent enough to review, and the build/typecheck pass.

Then wait for visual feedback before doing anything else.
