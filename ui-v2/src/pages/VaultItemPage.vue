<script setup lang="ts">
/**
 * The canonical Vault Item / preservation record. Answers "can I trust this
 * preserved thing, and what exactly does Stashd have for it?" — a
 * preservation record, not a media-detail page. See planning/DECISIONS.md,
 * "Preservation confidence is a primary user-facing property of a
 * canonical Vault Item."
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import { vaultItemFixtures } from '../fixtures/vaultItems'
import { vaultItemRecords } from '../fixtures/vaultItemRecords'
import type { PreservationStateKey } from '../types/preservationRecord'

const route = useRoute()

const overviewItem = computed(() => vaultItemFixtures.find(i => i.id === route.params.itemId))
const record = computed(() => vaultItemRecords.find(r => r.id === route.params.itemId))

const preservationDot: Record<PreservationStateKey, string> = { verified: 'bg-success', 'needs-attention': 'bg-error' }
const preservationText: Record<PreservationStateKey, string> = { verified: 'text-success', 'needs-attention': 'text-error' }
</script>

<template>
  <main v-if="overviewItem" class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink to="/vault" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Vault
    </RouterLink>

    <template v-if="record">
      <!-- Identity: plain text, no card, no assumed thumbnail -->
      <header class="space-y-1">
        <h1 class="font-mono text-2xl leading-tight text-highlighted">{{ record.title }}</h1>
        <p class="text-sm text-muted">
          {{ record.typeLabel }} · {{ record.sourceLabel }}
          <template v-if="record.publishedLabel"> · {{ record.publishedLabel }}</template>
          · {{ record.preservedLabel }}
        </p>
      </header>

      <!-- Preservation status: the page's first substantive section, and its
           one deliberately bordered/elevated surface — everything below sits
           on plain bg-muted rows or the page background, so this stays the
           standout without competing borders at every level. -->
      <section class="space-y-3">
        <h2 class="text-base font-medium text-highlighted">Preservation status</h2>
        <div class="space-y-3 rounded-md border border-default bg-muted p-4">
          <div class="flex items-center gap-2">
            <span class="size-2 rounded-full" :class="preservationDot[record.preservation.state]" />
            <span class="text-sm font-medium" :class="preservationText[record.preservation.state]">{{ record.preservation.label }}</span>
          </div>
          <p class="text-sm text-muted">{{ record.preservation.summary }}</p>
          <div class="flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-dimmed">
            <span>{{ record.preservation.assetCount }} durable asset{{ record.preservation.assetCount === 1 ? '' : 's' }}</span>
            <span v-if="record.preservation.lastVerifiedLabel">Last verified {{ record.preservation.lastVerifiedLabel }}</span>
            <span v-if="record.preservation.lastMismatchLabel" class="text-error">Mismatch detected {{ record.preservation.lastMismatchLabel }}</span>
          </div>

          <!-- Source availability is deliberately separate from preservation
               state — a gone upstream source is not an integrity failure. -->
          <div v-if="record.sourceAvailability" class="space-y-1 border-t border-default/60 pt-3">
            <div class="flex items-center gap-2">
              <span class="size-1.5 rounded-full bg-neutral-400" />
              <span class="text-xs text-toned">Source availability</span>
            </div>
            <p class="text-xs text-dimmed">{{ record.sourceAvailability.label }} — {{ record.sourceAvailability.note }}</p>
          </div>
        </div>
      </section>

      <!-- Durable Assets: roles first, filenames secondary, no Asset inspector -->
      <section class="space-y-3">
        <div class="flex items-baseline gap-2">
          <h2 class="text-base font-medium text-highlighted">Assets</h2>
          <span class="font-mono text-xs text-dimmed">{{ record.assets.length }}</span>
        </div>
        <div v-for="asset in record.assets" :key="asset.filename" class="flex items-center gap-3 rounded-md bg-muted p-3">
          <div class="min-w-0 flex-1">
            <p class="text-sm text-highlighted">{{ asset.role }}</p>
            <p class="truncate font-mono text-xs text-dimmed">{{ asset.filename }}</p>
            <p class="mt-0.5 text-xs text-dimmed">
              {{ asset.detail }}
              <template v-if="asset.derivedFrom"> · derived from {{ asset.derivedFrom }}</template>
            </p>
          </div>
          <UButton :label="asset.action.label" :icon="asset.action.icon" variant="ghost" color="neutral" size="sm" />
        </div>
      </section>

      <!-- Everything below is secondary: plain text on the page background,
           deliberately no surface, so it doesn't compete with the two
           sections above. -->
      <section class="space-y-2">
        <h2 class="text-sm font-medium text-toned">Provenance</h2>
        <p class="font-mono text-sm text-muted">{{ record.provenanceIntro }}</p>
        <div v-if="record.provenanceFacts.length > 0" class="space-y-1.5">
          <div v-for="fact in record.provenanceFacts" :key="fact.label">
            <p class="text-xs text-dimmed">{{ fact.label }}</p>
            <p class="font-mono text-sm text-toned">{{ fact.value }}</p>
          </div>
        </div>
      </section>

      <section class="space-y-2">
        <h2 class="text-sm font-medium text-toned">Organised in</h2>
        <ul class="space-y-1">
          <li v-for="stash in record.organisedIn" :key="stash" class="flex items-center gap-1.5 text-sm text-muted">
            <UIcon name="i-lucide-inbox" class="size-3.5 shrink-0 text-dimmed" />
            {{ stash }}
          </li>
        </ul>
      </section>

      <!-- Omitted entirely when empty, not shown as an empty state — an
           unused Item is the common case, not a state worth narrating. -->
      <section v-if="record.usedBy.length > 0" class="space-y-2">
        <h2 class="text-sm font-medium text-toned">Used by</h2>
        <ul class="space-y-1">
          <li v-for="broadcast in record.usedBy" :key="broadcast.name" class="flex items-center gap-1.5 text-sm text-muted">
            <UIcon name="i-lucide-radio" class="size-3.5 shrink-0 text-dimmed" />
            {{ broadcast.name }} <span class="text-dimmed">· {{ broadcast.kind }}</span>
          </li>
        </ul>
      </section>

      <!-- Preservation history: a short, restrained event list — meaningful
           evidence, not a footer note, but far quieter than status/Assets. -->
      <section class="space-y-2">
        <h2 class="text-sm font-medium text-toned">History</h2>
        <div class="divide-y divide-default/60 border-t border-default/60">
          <div v-for="event in record.history.slice(0, 3)" :key="`${event.dateLabel}-${event.label}`" class="flex items-baseline justify-between gap-3 py-1.5">
            <span class="font-mono text-xs text-dimmed">{{ event.dateLabel }}</span>
            <span class="text-xs text-toned">{{ event.label }}</span>
          </div>
        </div>
      </section>
    </template>

    <!-- Overview Items without a full record yet: enough identity to not be
         a dead end, honest that the rest isn't built. -->
    <template v-else>
      <header class="space-y-1">
        <h1 class="font-mono text-2xl leading-tight text-highlighted">{{ overviewItem.title }}</h1>
        <p class="text-sm text-muted">{{ overviewItem.sourceLabel }}</p>
      </header>
      <p class="text-sm text-muted">A full preservation record isn't available yet for this item.</p>
    </template>
  </main>

  <main v-else class="mx-auto max-w-2xl px-4 py-8 sm:px-8">
    <p class="text-sm text-muted">Item not found.</p>
  </main>
</template>
