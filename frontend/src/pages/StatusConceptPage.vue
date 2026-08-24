<script setup lang="ts">
/**
 * TEMPORARY specimen. Not linked from the shell nav; reachable at
 * /design/status-concept. The full Status concept was approved and
 * migrated to the real /status route (src/pages/StatusPage.vue) — that page
 * is now the source of truth. This keeps only the unified Resources surface
 * as a quick reference, since it's the newest/most novel pattern.
 */
</script>

<template>
  <main class="mx-auto max-w-md space-y-6 px-4 py-8 sm:px-8">
    <div>
      <h1 class="font-mono text-lg text-highlighted">Status — Resources specimen</h1>
      <p class="mt-1 text-sm text-muted">
        The full concept moved to the real Status page. See
        <RouterLink to="/status" class="text-toned underline underline-offset-2">/status</RouterLink>
        for the live implementation.
      </p>
    </div>

    <div class="space-y-4 rounded-md border border-default bg-muted p-4">
      <div>
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <p class="text-sm font-medium text-highlighted">Storage</p>
          <p class="font-mono text-xs text-dimmed">1.84 TB used · 1.16 TB available</p>
        </div>
        <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-elevated">
          <div class="h-full rounded-full bg-neutral-400" style="width: 61%" />
        </div>
        <div class="mt-3 space-y-1.5">
          <div class="flex items-center justify-between gap-3">
            <span class="text-xs text-toned">Vault</span>
            <span class="font-mono text-xs text-dimmed">1.52 TB · preserved</span>
          </div>
          <div class="flex items-center justify-between gap-3">
            <span class="text-xs text-toned">Broadcasts</span>
            <span class="font-mono text-xs text-dimmed">287 GB · rebuildable</span>
          </div>
          <div class="flex items-center justify-between gap-3">
            <span class="text-xs text-toned">Cache & temporary</span>
            <span class="font-mono text-xs text-dimmed">33 GB · reclaimable</span>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4 border-t border-default/60 pt-4">
        <div>
          <p class="text-xs text-dimmed">CPU</p>
          <p class="mt-0.5 font-mono text-base text-highlighted">8%</p>
          <p class="mt-0.5 text-xs text-dimmed">Idle</p>
        </div>
        <div>
          <p class="text-xs text-dimmed">Memory</p>
          <p class="mt-0.5 font-mono text-base text-highlighted">1.1 GB / 8 GB</p>
          <p class="mt-0.5 text-xs text-dimmed">Idle</p>
        </div>
      </div>
    </div>
  </main>
</template>
