<script setup lang="ts">
/**
 * TEMPORARY specimen. Not linked from the shell nav; reachable at
 * /design/vault-item-concept. The full canonical Item / preservation-record
 * concept was approved and migrated to the real /vault/:itemId route
 * (src/pages/VaultItemPage.vue) — that page is now the source of truth.
 * This keeps only the Preservation Status visual language as a quick
 * reference, since it's the newest/most novel pattern on that page.
 */
</script>

<template>
  <main class="mx-auto max-w-md space-y-6 px-4 py-8 sm:px-8">
    <div>
      <h1 class="font-mono text-lg text-highlighted">Vault Item — Preservation status specimen</h1>
      <p class="mt-1 text-sm text-muted">
        The full concept moved to the real Vault Item page. See
        <RouterLink to="/vault/vault-4" class="text-toned underline underline-offset-2">/vault/vault-4</RouterLink>
        (or vault-1/vault-5/vault-7) for the live implementation.
      </p>
    </div>

    <div class="space-y-4">
      <div class="space-y-3 rounded-md border border-default bg-muted p-4">
        <div class="flex items-center gap-2">
          <span class="size-2 rounded-full bg-success" />
          <span class="text-sm font-medium text-success">Verified</span>
        </div>
        <p class="text-sm text-muted">All durable assets match their recorded integrity data.</p>
        <div class="flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-dimmed">
          <span>2 durable assets</span>
          <span>Last verified 6 Aug 2026</span>
        </div>
      </div>

      <div class="space-y-3 rounded-md border border-default bg-muted p-4">
        <div class="flex items-center gap-2">
          <span class="size-2 rounded-full bg-error" />
          <span class="text-sm font-medium text-error">Needs attention</span>
        </div>
        <p class="text-sm text-muted">One durable asset no longer matches its recorded integrity data.</p>
        <div class="flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-dimmed">
          <span>1 durable asset</span>
          <span>Last verified 2 Aug 2026</span>
          <span class="text-error">Mismatch detected 7 Aug 2026</span>
        </div>
      </div>
    </div>
  </main>
</template>
