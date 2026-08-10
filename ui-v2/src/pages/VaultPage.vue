<script setup lang="ts">
/**
 * Vault overview — the archive-wide canonical Item inventory. NOT another
 * view of Stashes: a Stash organizes what you asked Stashd to preserve; the
 * Vault is what Stashd actually has, once, regardless of how many Stashes
 * reference it or Broadcasts use it. See planning/DECISIONS.md, "Vault
 * overview" for the full information-architecture rationale.
 */
import { computed, h, ref, resolveComponent } from 'vue'
import { useRouter } from 'vue-router'
import type { TableColumn, TableRow, DropdownMenuItem } from '@nuxt/ui'

import { stashFixtures } from '../fixtures/stashes'
import { vaultItemFixtures } from '../fixtures/vaultItems'
import { sourceFamilyIcon, sourceFamilyLabel, vaultSourceCatalog } from '../fixtures/vaultSources'
import type { VaultItemFixture, VaultItemType, VaultSourceFamily } from '../types/vaultItem'

const router = useRouter()

// Deliberately open unions, not a fixed media taxonomy — see types/vaultItem.ts.
const typeMeta: Record<VaultItemType, { label: string, icon: string }> = {
  video: { label: 'Video', icon: 'i-lucide-video' },
  audio: { label: 'Audio', icon: 'i-lucide-headphones' },
  document: { label: 'Document', icon: 'i-lucide-file-text' },
  photo: { label: 'Photo', icon: 'i-lucide-image' },
  'disc-capture': { label: 'Disc capture', icon: 'i-lucide-disc' }
}

// Fixture-only orientation total — not real Asset aggregation.
const vaultTotals = { itemCount: vaultItemFixtures.length, sizeLabel: '26.3 GB' }

const search = ref('')
const typeFilter = ref<'all' | VaultItemType>('all')

const typeFilterOptions = [
  { label: 'All types', value: 'all' },
  ...Object.entries(typeMeta).map(([value, meta]) => ({ label: meta.label, value }))
]

// --- Source: hierarchical, searchable picker -------------------------------
// One UDropdownMenu with nested `children` (family → specific source) and
// its built-in `filter` search — the smallest native Nuxt UI composition that
// gives hierarchy + type-to-search + keyboard/touch support without a custom
// tree-select primitive. Family order follows fixtures/vaultSources.ts.
type SourceSelection = { kind: 'all' } | { kind: 'family', family: VaultSourceFamily } | { kind: 'source', sourceId: string }

const sourceSelection = ref<SourceSelection>({ kind: 'all' })

const sourceFamilies = Array.from(new Set(vaultSourceCatalog.map(s => s.familyKey)))

const sourceMenuItems = computed<DropdownMenuItem[]>(() => sourceFamilies.map(family => ({
  label: sourceFamilyLabel[family],
  icon: sourceFamilyIcon[family],
  children: [
    {
      label: `All ${sourceFamilyLabel[family]}`,
      icon: sourceFamilyIcon[family],
      onSelect: () => { sourceSelection.value = { kind: 'family', family } }
    },
    ...vaultSourceCatalog.filter(s => s.familyKey === family).map(s => ({
      label: s.label,
      onSelect: () => { sourceSelection.value = { kind: 'source', sourceId: s.id } }
    }))
  ]
})))

const sourceTriggerLabel = computed(() => {
  const selection = sourceSelection.value
  if (selection.kind === 'all') return 'Source'
  if (selection.kind === 'family') return sourceFamilyLabel[selection.family]
  return vaultSourceCatalog.find(s => s.id === selection.sourceId)?.label ?? 'Source'
})

// --- Secondary filters: Stash + Preserved date range ------------------------
const stashFilter = ref('all')
const stashFilterOptions = [
  { label: 'All stashes', value: 'all' },
  ...stashFixtures.map(s => ({ label: s.name, value: s.id }))
]

type PreservedRange = 'all' | '7d' | '30d' | 'year'
const preservedRangeFilter = ref<PreservedRange>('all')
const preservedRangeOptions: { label: string, value: PreservedRange }[] = [
  { label: 'Any time', value: 'all' },
  { label: 'Last 7 days', value: '7d' },
  { label: 'Last 30 days', value: '30d' },
  { label: 'This year', value: 'year' }
]

function withinPreservedRange(iso: string, range: PreservedRange) {
  if (range === 'all') return true
  const preservedAt = new Date(iso)
  const now = new Date()
  if (range === 'year') return preservedAt.getFullYear() === now.getFullYear()
  const days = range === '7d' ? 7 : 30
  return now.getTime() - preservedAt.getTime() <= days * 86_400_000
}

const secondaryFilterCount = computed(() => (stashFilter.value !== 'all' ? 1 : 0) + (preservedRangeFilter.value !== 'all' ? 1 : 0))
const secondaryFiltersOpen = ref(false)

function clearSecondaryFilters() {
  stashFilter.value = 'all'
  preservedRangeFilter.value = 'all'
}

function clearAllFilters() {
  search.value = ''
  typeFilter.value = 'all'
  sourceSelection.value = { kind: 'all' }
  clearSecondaryFilters()
}

// --- Combined result set -----------------------------------------------------
// Recently preserved first — the one sensible default ordering for this slice.
const sortedItems = computed(() => [...vaultItemFixtures].sort((a, b) => b.preservedAt.localeCompare(a.preservedAt)))

const filteredItems = computed(() => {
  const query = search.value.trim().toLowerCase()
  const source = sourceSelection.value
  return sortedItems.value.filter((item) => {
    if (typeFilter.value !== 'all' && item.type !== typeFilter.value) return false
    if (source.kind === 'family' && item.sourceFamily !== source.family) return false
    if (source.kind === 'source' && item.sourceId !== source.sourceId) return false
    if (stashFilter.value !== 'all' && !item.stashIds.includes(stashFilter.value)) return false
    if (!withinPreservedRange(item.preservedAt, preservedRangeFilter.value)) return false
    if (query && !item.title.toLowerCase().includes(query) && !item.sourceLabel.toLowerCase().includes(query)) return false
    return true
  })
})

// Fixture-only demo arithmetic — not real Asset-size aggregation.
function parseSizeToGb(label: string) {
  const match = label.match(/^([\d.]+)\s*(GB|MB)$/i)
  if (!match) return 0
  const value = Number.parseFloat(match[1])
  return match[2].toUpperCase() === 'MB' ? value / 1024 : value
}

function formatGb(gb: number) {
  if (gb < 0.1) return `${Math.round(gb * 1024)} MB`
  return `${gb.toFixed(gb < 10 ? 1 : 0)} GB`
}

const resultSummary = computed(() => {
  const total = vaultItemFixtures.length
  const shown = filteredItems.value.length
  if (shown === total) return `${total.toLocaleString()} items · ${vaultTotals.sizeLabel} preserved`
  const shownSizeGb = filteredItems.value.reduce((sum, item) => sum + parseSizeToGb(item.sizeLabel), 0)
  return `${shown.toLocaleString()} of ${total.toLocaleString()} items · ${formatGb(shownSizeGb)}`
})

function absoluteTime(iso: string) {
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

function preservedDate(iso: string) {
  return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

function openItem(item: VaultItemFixture) {
  router.push(`/vault/${item.id}`)
}

// Recognition, not consumption: a thumbnail when real artwork exists, a
// restrained type icon otherwise — a document should never look like a
// broken video row. See "Mixed-media recognition" in DECISIONS.md.
function vaultThumbnail(item: VaultItemFixture) {
  const meta = typeMeta[item.type]
  if (item.art) {
    return h('div', { class: ['flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-gradient-to-br sm:w-16', item.art] }, [
      h(resolveComponent('UIcon'), { name: meta.icon, class: 'size-3.5 text-dimmed' })
    ])
  }
  return h('div', { class: 'flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-elevated sm:w-16' }, [
    h(resolveComponent('UIcon'), { name: meta.icon, class: 'size-4 text-dimmed' })
  ])
}

function titleCell(item: VaultItemFixture) {
  return h('div', { class: 'flex items-center gap-2.5' }, [
    vaultThumbnail(item),
    h('div', { class: 'min-w-0' }, [
      h('p', { class: 'truncate font-mono text-sm text-highlighted' }, item.title),
      h('p', { class: 'truncate text-xs text-dimmed' }, item.sourceLabel)
    ])
  ])
}

function typeCell(item: VaultItemFixture) {
  const meta = typeMeta[item.type]
  return h('span', { class: 'inline-flex items-center gap-1.5 text-xs text-toned' }, [
    h(resolveComponent('UIcon'), { name: meta.icon, class: 'size-3.5 shrink-0 text-dimmed' }),
    meta.label
  ])
}

function preservedCell(item: VaultItemFixture) {
  return h(resolveComponent('UTooltip'), { text: absoluteTime(item.preservedAt) }, () =>
    h('time', { datetime: item.preservedAt, class: 'whitespace-nowrap font-mono text-xs text-dimmed' }, preservedDate(item.preservedAt)))
}

function contextCell(item: VaultItemFixture) {
  const chips = []
  if (item.stashIds.length > 1) {
    chips.push(h(resolveComponent('UTooltip'), { text: `In ${item.stashIds.length} stashes` }, () =>
      h('span', { class: 'inline-flex shrink-0 items-center gap-1 font-mono text-xs text-dimmed' }, [
        h(resolveComponent('UIcon'), { name: 'i-lucide-inbox', class: 'size-3.5' }),
        item.stashIds.length
      ])))
  }
  if (item.broadcastCount > 0) {
    chips.push(h(resolveComponent('UTooltip'), { text: `Used by ${item.broadcastCount} broadcast${item.broadcastCount === 1 ? '' : 's'}` }, () =>
      h('span', { class: 'inline-flex shrink-0 items-center gap-1 font-mono text-xs text-dimmed' }, [
        h(resolveComponent('UIcon'), { name: 'i-lucide-radio', class: 'size-3.5' }),
        item.broadcastCount
      ])))
  }
  return chips.length > 0 ? h('div', { class: 'flex items-center gap-3' }, chips) : null
}

const columns: TableColumn<VaultItemFixture>[] = [
  { accessorKey: 'title', header: 'Item', cell: ({ row }) => titleCell(row.original) },
  { accessorKey: 'type', header: 'Type', cell: ({ row }) => typeCell(row.original) },
  { accessorKey: 'preservedAt', header: 'Preserved', cell: ({ row }) => preservedCell(row.original) },
  { accessorKey: 'sizeLabel', header: 'Size', cell: ({ row }) => h('span', { class: 'font-mono text-xs text-muted' }, row.original.sizeLabel) },
  { id: 'context', header: '', cell: ({ row }) => contextCell(row.original) }
]

// Standard desktop table surface: one continuous bg-muted panel with a
// subtle border, an integrated bg-elevated header strip, and rows divided
// (not carded) within it — see planning/DECISIONS.md, "Desktop table surface".
const tableUi = {
  thead: 'bg-elevated/60',
  th: 'font-mono text-xs uppercase tracking-wider text-dimmed py-2 px-3',
  td: 'py-2 px-3',
  tbody: 'divide-y divide-default/60 [&_tr]:cursor-pointer [&_tr]:transition-colors [&_tr]:hover:bg-elevated/60'
}

function onSelectRow(_event: Event, row: TableRow<VaultItemFixture>) {
  openItem(row.original)
}
</script>

<template>
  <main class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-8">
    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">Vault</h1>
      <p class="text-sm text-muted">Everything Stashd has preserved.</p>
      <p class="font-mono text-xs text-dimmed">{{ resultSummary }}</p>
    </header>

    <div class="space-y-2">
      <UInput v-model="search" placeholder="Search the vault" icon="i-lucide-search" size="lg" class="w-full" />

      <div class="flex flex-wrap items-center gap-2">
        <USelect v-model="typeFilter" :items="typeFilterOptions" value-key="value" class="w-auto min-w-32" />

        <UDropdownMenu :items="sourceMenuItems" :filter="{ placeholder: 'Search sources…' }" :content="{ align: 'start' }">
          <UButton :label="sourceTriggerLabel" trailing-icon="i-lucide-chevron-down" variant="outline" color="neutral" class="min-w-32" />
        </UDropdownMenu>

        <UPopover v-model:open="secondaryFiltersOpen">
          <UButton
            :label="secondaryFilterCount > 0 ? `Filters · ${secondaryFilterCount}` : 'Filters'"
            trailing-icon="i-lucide-chevron-down"
            variant="outline"
            color="neutral"
          />
          <template #content>
            <div class="w-72 space-y-4 p-4">
              <div class="space-y-1.5">
                <p class="text-xs text-dimmed">Stash</p>
                <USelect v-model="stashFilter" :items="stashFilterOptions" value-key="value" class="w-full" />
              </div>
              <div class="space-y-1.5">
                <p class="text-xs text-dimmed">Preserved</p>
                <USelect v-model="preservedRangeFilter" :items="preservedRangeOptions" value-key="value" class="w-full" />
              </div>
              <UButton v-if="secondaryFilterCount > 0" label="Clear" variant="ghost" color="neutral" size="xs" @click="clearSecondaryFilters" />
            </div>
          </template>
        </UPopover>
      </div>
    </div>

    <template v-if="filteredItems.length > 0">
      <div class="hidden overflow-hidden rounded-md border border-default bg-muted md:block">
        <UTable :data="filteredItems" :columns="columns" :ui="tableUi" class="text-sm" @select="onSelectRow" />
      </div>

      <!-- UTable is a literal <table>, so row navigation below md is a RouterLink
           wrapping the same fixture as purpose-built rows instead. -->
      <div class="space-y-2 md:hidden">
        <RouterLink
          v-for="item in filteredItems"
          :key="item.id"
          :to="`/vault/${item.id}`"
          class="flex items-center gap-3 rounded-md bg-muted p-3 transition-colors hover:bg-elevated/40"
        >
          <div v-if="item.art" :class="['flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-gradient-to-br', item.art]">
            <UIcon :name="typeMeta[item.type].icon" class="size-3.5 text-dimmed" />
          </div>
          <div v-else class="flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-elevated">
            <UIcon :name="typeMeta[item.type].icon" class="size-4 text-dimmed" />
          </div>

          <div class="min-w-0 flex-1 space-y-1">
            <p class="truncate font-mono text-sm text-highlighted">{{ item.title }}</p>
            <p class="truncate text-xs text-dimmed">{{ item.sourceLabel }}</p>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-dimmed">
              <UTooltip :text="absoluteTime(item.preservedAt)">
                <time :datetime="item.preservedAt">{{ preservedDate(item.preservedAt) }}</time>
              </UTooltip>
              <span>{{ item.sizeLabel }}</span>
              <UTooltip v-if="item.stashIds.length > 1" :text="`In ${item.stashIds.length} stashes`">
                <span class="inline-flex items-center gap-1"><UIcon name="i-lucide-inbox" class="size-3.5" />{{ item.stashIds.length }}</span>
              </UTooltip>
              <UTooltip v-if="item.broadcastCount > 0" :text="`Used by ${item.broadcastCount} broadcast${item.broadcastCount === 1 ? '' : 's'}`">
                <span class="inline-flex items-center gap-1"><UIcon name="i-lucide-radio" class="size-3.5" />{{ item.broadcastCount }}</span>
              </UTooltip>
            </div>
          </div>
        </RouterLink>
      </div>
    </template>

    <div v-else class="rounded-md bg-muted p-4 text-center">
      <p class="text-sm text-muted">No items match these filters.</p>
      <UButton label="Clear filters" variant="ghost" color="neutral" size="sm" class="mt-2" @click="clearAllFilters" />
    </div>
  </main>
</template>
