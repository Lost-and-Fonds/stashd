<script setup lang="ts">
/**
 * TEMPORARY exploration route. Not linked from the shell nav; reachable at
 * /design/stash-detail-concepts. Refined hybrid of Concept C (summary +
 * operational detail), chosen after comparing three whole-page concepts.
 * Delete this file once approved and migrated into StashDetailPage.vue.
 */
import { computed, ref } from 'vue'
import { useClipboard } from '@vueuse/core'

import { broadcastFixtures } from '../fixtures/broadcasts'
import { inputFixtures } from '../fixtures/inputs'
import { stashFixtures } from '../fixtures/stashes'
import type { BroadcastFixture } from '../types/broadcast'

const stash = computed(() => stashFixtures.find(s => s.id === 'stash-1')!)
const inputs = computed(() => inputFixtures.filter(i => i.stashId === 'stash-1'))
const broadcasts = computed(() => broadcastFixtures.filter(b => b.stashId === 'stash-1'))

const statusMeta = {
  active: { label: 'active', dot: 'bg-success', text: 'text-success' },
  paused: { label: 'paused', dot: 'bg-neutral-400', text: 'text-dimmed' },
  'needs-attention': { label: 'needs attention', dot: 'bg-error', text: 'text-error' }
} as const

function monogram(name: string) {
  return name.charAt(0).toUpperCase()
}

const { copy } = useClipboard()
const copiedId = ref<string | null>(null)

function copyPublishedUrl(broadcast: BroadcastFixture) {
  if (!broadcast.publishedUrl) return
  copy(broadcast.publishedUrl)
  copiedId.value = broadcast.id
  setTimeout(() => { if (copiedId.value === broadcast.id) copiedId.value = null }, 1500)
}

// Structured, labeled facts for the Broadcast operational region — replaces
function rebuiltValue(b: BroadcastFixture) {
  if (b.buildState === 'stale') {
    return b.status === 'needs-attention' ? `Failed · last built ${b.lastRebuild}` : `Needs rebuild · last built ${b.lastRebuild}`
  }
  return b.lastRebuild
}

function broadcastFacts(b: BroadcastFixture) {
  const published = `${b.itemsPublished.toLocaleString()} / ${b.itemsTotal.toLocaleString()}`
  return [
    { label: 'Type', value: b.formLabel },
    { label: 'Published', value: published },
    { label: 'Size', value: b.sizeLabel },
    { label: 'Rebuilt', value: rebuiltValue(b) },
    { label: 'Destination', value: b.kind === 'jellyfin' ? 'Jellyfin media library' : 'Plex media library' }
  ]
}
</script>

<template>
  <main class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-8">
    <div>
      <h1 class="font-mono text-lg text-highlighted">Stash detail — refined concept</h1>
      <p class="mt-1 text-sm text-muted">
        Hybrid of Concept C: stronger section headings, structured Broadcast facts. Temporary route, not part of the shell navigation.
      </p>
    </div>

    <div class="rounded-lg border border-default bg-default p-4 sm:p-6">
      <!-- Stash header: identity + status inline, one short summary line, actions -->
      <header class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-4">
          <div class="flex size-14 shrink-0 items-center justify-center rounded-md bg-elevated font-mono text-lg text-muted">
            {{ monogram(stash.name) }}
          </div>
          <div class="min-w-0">
            <h2 class="truncate font-mono text-2xl leading-tight text-highlighted">{{ stash.name }}</h2>
            <div class="mt-2 flex items-center gap-1.5">
              <span class="size-1.5 rounded-full" :class="statusMeta[stash.status].dot" />
              <span class="text-xs" :class="statusMeta[stash.status].text">{{ statusMeta[stash.status].label }}</span>
            </div>
            <p class="mt-1 text-sm text-muted">{{ stash.itemCount.toLocaleString() }} items · {{ stash.sizeLabel }} · updated {{ stash.lastActivity }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <UButton label="Rebuild" icon="i-lucide-refresh-cw" variant="subtle" color="neutral" size="sm" class="hidden sm:flex" />
          <UDropdownMenu :items="[[{ label: 'Rebuild broadcasts', icon: 'i-lucide-refresh-cw' }, { label: 'Pause', icon: 'i-lucide-pause' }], [{ label: 'Delete', icon: 'i-lucide-trash-2', color: 'error' }]]">
            <UButton icon="i-lucide-ellipsis-vertical" aria-label="More actions" variant="ghost" color="neutral" size="md" />
          </UDropdownMenu>
        </div>
      </header>

      <div class="mt-8 space-y-8">
        <!-- Inputs: confident section heading, still a simple compact row -->
        <div class="space-y-3">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-baseline gap-2">
              <h3 class="text-base font-medium text-highlighted">Inputs</h3>
              <span class="font-mono text-xs text-dimmed">{{ inputs.length }}</span>
            </div>
            <UButton label="Add input" icon="i-lucide-plus" variant="ghost" color="neutral" size="sm" />
          </div>

          <div v-for="input in inputs" :key="input.id" class="flex items-center gap-3 rounded-md bg-muted p-3">
            <UIcon :name="input.provider === 'rss' ? 'i-lucide-rss' : 'i-lucide-youtube'" class="size-4 shrink-0 text-muted" />
            <div class="min-w-0 flex-1">
              <p class="truncate font-mono text-sm text-highlighted">{{ input.identity }}</p>
              <p class="mt-0.5 flex items-center gap-1.5 text-xs text-dimmed">
                <span class="size-1.5 rounded-full" :class="statusMeta[input.status].dot" />
                <span :class="statusMeta[input.status].text">{{ statusMeta[input.status].label }}</span>
                · {{ input.providerLabel }} · checked {{ input.lastChecked }}
              </p>
            </div>
            <UButton icon="i-lucide-refresh-cw" aria-label="Sync now" variant="ghost" color="neutral" size="sm" />
            <UButton icon="i-lucide-ellipsis-vertical" aria-label="More actions" variant="ghost" color="neutral" size="sm" />
          </div>
        </div>

        <!-- Broadcasts: confident heading, two-tier cards with structured facts -->
        <div class="space-y-3">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-baseline gap-2">
              <h3 class="text-base font-medium text-highlighted">Broadcasts</h3>
              <span class="font-mono text-xs text-dimmed">{{ broadcasts.length }}</span>
            </div>
            <UButton label="New broadcast" icon="i-lucide-plus" variant="ghost" color="neutral" size="sm" />
          </div>

          <div v-for="broadcast in broadcasts" :key="broadcast.id" class="rounded-md bg-muted p-3.5">
            <!-- Tier 1: recognize -->
            <div class="flex items-center gap-3">
              <UIcon name="i-lucide-box" class="size-4 shrink-0 text-muted" />
              <div class="min-w-0 flex-1">
                <p class="truncate font-mono text-sm text-highlighted">{{ broadcast.name }}</p>
                <p class="mt-0.5 flex items-center gap-1.5 text-xs" :class="statusMeta[broadcast.status].text">
                  <span class="size-1.5 rounded-full" :class="statusMeta[broadcast.status].dot" />
                  {{ statusMeta[broadcast.status].label }}
                </p>
              </div>
              <UButton
                v-if="broadcast.buildState !== 'rebuilding'"
                label="Rebuild"
                icon="i-lucide-refresh-cw" variant="subtle" color="neutral" size="sm"
              />
              <UDropdownMenu :items="[[{ label: 'Reconfigure', icon: 'i-lucide-settings' }], [{ label: 'Remove', icon: 'i-lucide-trash-2', color: 'error' }]]">
                <UButton icon="i-lucide-ellipsis-vertical" aria-label="More actions" variant="ghost" color="neutral" size="sm" />
              </UDropdownMenu>
            </div>

            <!-- Tier 2: inspect — inset operational region, distinct surface -->
            <div class="mt-3 rounded-md bg-elevated p-3">
              <!-- Structured facts: stacked key/value rows by default (phone-first),
                   becoming a horizontal wrap of label-over-value blocks at sm+. -->
              <div class="flex flex-col gap-1.5 sm:flex-row sm:flex-wrap sm:gap-x-6 sm:gap-y-2">
                <div v-for="fact in broadcastFacts(broadcast)" :key="fact.label" class="flex items-center justify-between gap-3 sm:block sm:justify-normal">
                  <p class="shrink-0 font-mono text-[10px] uppercase tracking-wider text-dimmed sm:mb-1">{{ fact.label }}</p>
                  <p class="truncate font-mono text-xs text-toned">{{ fact.value }}</p>
                </div>
              </div>

              <div v-if="broadcast.publishedUrl" class="mt-3 space-y-1.5">
                <div class="flex items-center gap-2 rounded-md bg-accented p-2">
                  <UIcon name="i-lucide-rss" class="size-3.5 shrink-0 text-dimmed" />
                  <p class="min-w-0 flex-1 truncate font-mono text-xs text-muted">{{ broadcast.publishedUrl }}</p>
                  <UButton
                    :label="copiedId === broadcast.id ? 'Copied' : 'Copy'"
                    :icon="copiedId === broadcast.id ? 'i-lucide-check' : 'i-lucide-copy'"
                    :color="copiedId === broadcast.id ? 'success' : 'neutral'"
                    variant="soft" size="xs" @click="copyPublishedUrl(broadcast)"
                  />
                  <UButton icon="i-lucide-external-link" :to="broadcast.publishedUrl" target="_blank" aria-label="Open published resource" variant="ghost" color="neutral" size="xs" />
                </div>
                <p class="text-xs text-dimmed">Anyone with this link can access the feed — treat it like a password.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</template>
