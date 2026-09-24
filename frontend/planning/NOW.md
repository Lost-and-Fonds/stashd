# NOW

## Current phase

**Phase 4 — Pages, one slice at a time**

## Goal

Build approved Stashd pages one slice at a time on the established visual
foundation, using the real API wherever the backend supports the required behavior.

## Allowed in this phase

- the specifically requested page slice
- API-backed loading, empty, and error states for the requested slice
- responsive behavior for desktop and phone-sized layouts
- build/typecheck fixes inside the frontend

## Explicitly not in this phase

- backend/API integration
- auth integration
- global state architecture
- legacy UI changes
- production build/deployment integration with the PHP app
- adjacent pages or approved slices not named by the current task

## Stop condition

Stop when the requested slice is reviewable with API-backed state and the
relevant build/typecheck checks pass.

Then wait for visual feedback or the next explicitly requested slice.
