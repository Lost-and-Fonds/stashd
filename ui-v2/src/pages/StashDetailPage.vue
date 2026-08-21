<script setup lang="ts">
import { computed, h, onUnmounted, reactive, ref, resolveComponent, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useClipboard } from '@vueuse/core'
import type { TableColumn, FormError, FormSubmitEvent } from '@nuxt/ui'

import OperationProgress from '../components/OperationProgress.vue'
import PreflightSummary from '../components/PreflightSummary.vue'
import { broadcastFixtures } from '../fixtures/broadcasts'
import { inputFixtures } from '../fixtures/inputs'
import { itemFixtures } from '../fixtures/items'
import { stashFixtures } from '../fixtures/stashes'
import type { StashStatus } from '../types/stash'
import type { InputStatus, InputProvider, InputFixture, InputFilters } from '../types/input'
import type { BroadcastStatus, BroadcastKind, BroadcastFixture } from '../types/broadcast'
import type { ItemFixture, ItemStatus } from '../types/item'
import type { PreflightOperation, PreflightState, StorageEstimate } from '../types/preflight'

const route = useRoute()

const stash = computed(() => stashFixtures.find(s => s.id === route.params.id))
const inputs = computed(() => inputFixtures.filter(i => i.stashId === route.params.id))
const broadcasts = computed(() => broadcastFixtures.filter(b => b.stashId === route.params.id))
const items = computed(() => itemFixtures.filter(i => i.stashId === route.params.id))

// Quiet dot + visible text label — status meaning must not rely on colour
// alone. Shared across Stash/Input/Broadcast summaries.
const statusMeta: Record<StashStatus | InputStatus | BroadcastStatus, { label: string, dot: string, text: string }> = {
  active: { label: 'active', dot: 'bg-success', text: 'text-success' },
  paused: { label: 'paused', dot: 'bg-neutral-400', text: 'text-dimmed' },
  'needs-attention': { label: 'needs attention', dot: 'bg-error', text: 'text-error' }
}

const providerIcon: Record<InputProvider, string> = {
  'youtube-channel': 'i-lucide-youtube',
  'youtube-playlist': 'i-lucide-youtube',
  rss: 'i-lucide-rss'
}

const broadcastIcon: Record<BroadcastKind, string> = {
  podcast: 'i-lucide-rss',
  jellyfin: 'i-lucide-tv',
  plex: 'i-lucide-tv'
}

function monogram(name: string) {
  return name.charAt(0).toUpperCase()
}

const stashActions = [
  [{ label: 'Rebuild broadcasts', icon: 'i-lucide-refresh-cw' }, { label: 'Pause', icon: 'i-lucide-pause' }],
  [{ label: 'Delete', icon: 'i-lucide-trash-2', color: 'error' as const }]
]

const inputOverflowActions = [
  [{ label: 'Open source', icon: 'i-lucide-external-link' }, { label: 'Edit filters', icon: 'i-lucide-filter' }],
  [{ label: 'Remove', icon: 'i-lucide-trash-2', color: 'error' as const }]
]

// Quiet "N filters" hint on the compact Input row — never the filter values
// themselves. Only counts filters that differ from their default (unset regex,
// unchecked provider option).
function activeFilterCount(filters?: InputFilters) {
  if (!filters) return 0
  return [filters.titleRegexInclude, filters.titleRegexExclude, filters.includeShorts, filters.includeLive].filter(Boolean).length
}

// Add Input: a small contextual modal, not a page. Local fixture recognition
// only — mirrors the New Stash workflow's detectSource(), scoped to this file.
interface DetectedInputSource {
  provider: InputProvider
  providerLabel: string
  icon: string
  identity: string
}

function detectInputSource(url: string): DetectedInputSource | null {
  const trimmed = url.trim()
  if (!trimmed) return null

  const channel = trimmed.match(/youtube\.com\/@([\w.-]+)/i)
  if (channel) {
    return { provider: 'youtube-channel', providerLabel: 'YouTube channel', icon: 'i-lucide-youtube', identity: `@${channel[1]}` }
  }
  if (/youtube\.com\/playlist/i.test(trimmed)) {
    return { provider: 'youtube-playlist', providerLabel: 'YouTube playlist', icon: 'i-lucide-youtube', identity: trimmed }
  }
  if (/\.xml($|[?#])/i.test(trimmed) || /\/feed\/?($|[?#])/i.test(trimmed)) {
    const host = trimmed.match(/^https?:\/\/(?:www\.)?([\w.-]+)/i)
    return { provider: 'rss', providerLabel: 'RSS feed', icon: 'i-lucide-rss', identity: host ? host[1] : trimmed }
  }
  return null
}

function isValidRegex(pattern: string) {
  try {
    void new RegExp(pattern)
    return true
  } catch {
    return false
  }
}

const addInputOpen = ref(false)

const addInputForm = reactive({
  sourceUrl: '',
  titleRegexInclude: '',
  titleRegexExclude: '',
  includeShorts: false,
  includeLive: false
})

const addInputFiltersOpen = ref(false)
const addInputDetected = computed(() => detectInputSource(addInputForm.sourceUrl))

const addInputActiveFilterCount = computed(() => activeFilterCount({
  titleRegexInclude: addInputForm.titleRegexInclude.trim() || undefined,
  titleRegexExclude: addInputForm.titleRegexExclude.trim() || undefined,
  includeShorts: addInputForm.includeShorts,
  includeLive: addInputForm.includeLive
}))

function resetAddInputForm() {
  addInputForm.sourceUrl = ''
  addInputForm.titleRegexInclude = ''
  addInputForm.titleRegexExclude = ''
  addInputForm.includeShorts = false
  addInputForm.includeLive = false
  addInputFiltersOpen.value = false
  clearInputAnalysisTimers()
  inputPreflight.value = null
}

function openAddInput() {
  resetAddInputForm()
  addInputOpen.value = true
}

function clearAddInputFilters() {
  addInputForm.titleRegexInclude = ''
  addInputForm.titleRegexExclude = ''
  addInputForm.includeShorts = false
  addInputForm.includeLive = false
}

// "What Stashd will do" for Add Input — same reusable pattern as New
// Broadcast, different fixture arithmetic and operation vocabulary
// (Download/Already held instead of Hardlink/Transcode). A pasted
// "@criticalrole" channel is a deliberate fixture stand-in for a very large
// channel: item discovery still resolves quickly, but the storage estimate
// takes noticeably longer — demonstrating Preflight staying usable while a
// slow estimate continues.
function formatGB(value: number) {
  return `${value.toFixed(value < 10 ? 1 : 0)} GB`
}

function inputSourceBaseCount(provider: InputProvider) {
  return provider === 'youtube-channel' ? 96 : provider === 'youtube-playlist' ? 40 : 24
}

function isSlowInputSource(detected: DetectedInputSource) {
  return detected.provider === 'youtube-channel' && detected.identity.toLowerCase() === '@criticalrole'
}

function computeInputMatchedCount(detected: DetectedInputSource, state: typeof addInputForm) {
  let count = isSlowInputSource(detected) ? 1200 : inputSourceBaseCount(detected.provider)
  if (state.titleRegexInclude.trim()) count = Math.round(count * 0.5)
  if (state.titleRegexExclude.trim()) count = Math.round(count * 0.85)
  if (detected.provider === 'youtube-channel' && state.includeShorts) count = Math.round(count * 1.15)
  if (detected.provider === 'youtube-channel' && state.includeLive) count = Math.round(count * 1.05)
  return Math.max(0, count)
}

function buildInputOperations(matched: number): PreflightOperation[] {
  const newCount = Math.round(matched * 0.15)
  const heldCount = matched - newCount
  const operations: PreflightOperation[] = []
  if (newCount > 0) operations.push({ key: 'download', label: 'Download', itemCount: newCount, storageLabel: `~${formatGB(newCount * 3.1)} new to Vault`, icon: 'i-lucide-download' })
  if (heldCount > 0) operations.push({ key: 'already-held', label: 'Already held', itemCount: heldCount, storageLabel: 'no additional storage', icon: 'i-lucide-check' })
  return operations
}

function buildInputStorage(matched: number): StorageEstimate {
  const newCount = Math.round(matched * 0.15)
  if (newCount === 0) return { kind: 'none' }
  return { kind: 'range', lowLabel: formatGB(newCount * 2.8), highLabel: formatGB(newCount * 3.4) }
}

const inputPreflight = ref<PreflightState | null>(null)
let inputItemsTimer: ReturnType<typeof setTimeout> | undefined
let inputStorageTimer: ReturnType<typeof setTimeout> | undefined

function clearInputAnalysisTimers() {
  clearTimeout(inputItemsTimer)
  clearTimeout(inputStorageTimer)
}

function runInputAnalysis() {
  clearInputAnalysisTimers()
  inputPreflight.value = null

  const detected = addInputDetected.value
  if (!detected) return

  const snapshot = {
    url: addInputForm.sourceUrl,
    include: addInputForm.titleRegexInclude,
    exclude: addInputForm.titleRegexExclude,
    shorts: addInputForm.includeShorts,
    live: addInputForm.includeLive
  }
  const isStale = () => addInputForm.sourceUrl !== snapshot.url
    || addInputForm.titleRegexInclude !== snapshot.include
    || addInputForm.titleRegexExclude !== snapshot.exclude
    || addInputForm.includeShorts !== snapshot.shorts
    || addInputForm.includeLive !== snapshot.live

  const matched = computeInputMatchedCount(detected, addInputForm)
  const slow = isSlowInputSource(detected)

  inputItemsTimer = setTimeout(() => {
    if (isStale()) return
    const operations = buildInputOperations(matched)
    inputPreflight.value = {
      status: 'analyzing',
      plan: { itemCountLabel: `${matched.toLocaleString()} items found`, operations, storage: { kind: 'calculating' } }
    }

    inputStorageTimer = setTimeout(() => {
      if (isStale()) return
      inputPreflight.value = {
        status: 'ready',
        plan: {
          itemCountLabel: `${matched.toLocaleString()} items found`,
          operations,
          storage: buildInputStorage(matched),
          notes: slow ? ['Large channels can take longer to estimate.'] : undefined
        }
      }
    }, slow ? 4000 : 900)
  }, 300)
}

watch(() => [
  addInputForm.sourceUrl,
  addInputForm.titleRegexInclude,
  addInputForm.titleRegexExclude,
  addInputForm.includeShorts,
  addInputForm.includeLive
], runInputAnalysis)

onUnmounted(clearInputAnalysisTimers)

const addInputCommitLabel = computed(() => inputPreflight.value?.status === 'analyzing' ? 'Add without estimate' : 'Add input')

function validateAddInputForm(state: Partial<typeof addInputForm>): FormError[] {
  const errors: FormError[] = []
  const url = (state.sourceUrl ?? '').trim()
  if (!url) {
    errors.push({ name: 'sourceUrl', message: 'Enter a source URL to continue.' })
  } else if (!detectInputSource(url)) {
    errors.push({ name: 'sourceUrl', message: 'We don’t recognize this link yet — try a YouTube channel, playlist, or RSS feed URL.' })
  }
  if (state.titleRegexInclude?.trim() && !isValidRegex(state.titleRegexInclude.trim())) {
    errors.push({ name: 'titleRegexInclude', message: 'That doesn’t look like a valid regular expression.' })
  }
  if (state.titleRegexExclude?.trim() && !isValidRegex(state.titleRegexExclude.trim())) {
    errors.push({ name: 'titleRegexExclude', message: 'That doesn’t look like a valid regular expression.' })
  }
  return errors
}

function onAddInputSubmit(event: FormSubmitEvent<typeof addInputForm>) {
  if (!stash.value) return
  const detected = detectInputSource(event.data.sourceUrl)!
  const nowIso = new Date().toISOString()

  const filters: InputFilters = {}
  if (event.data.titleRegexInclude.trim()) filters.titleRegexInclude = event.data.titleRegexInclude.trim()
  if (event.data.titleRegexExclude.trim()) filters.titleRegexExclude = event.data.titleRegexExclude.trim()
  if (detected.provider === 'youtube-channel' && event.data.includeShorts) filters.includeShorts = true
  if (detected.provider === 'youtube-channel' && event.data.includeLive) filters.includeLive = true

  const newInput: InputFixture = {
    id: `${stash.value.id}-input-${inputs.value.length + 1}-${Date.now()}`,
    stashId: stash.value.id,
    provider: detected.provider,
    providerLabel: detected.providerLabel,
    identity: detected.identity,
    url: event.data.sourceUrl.trim(),
    status: 'active',
    syncMode: 'automatic',
    lastChecked: 'just now',
    lastCheckedAt: nowIso,
    ...(Object.keys(filters).length > 0 ? { filters } : {})
  }

  inputFixtures.push(newInput)
  addInputOpen.value = false
}

function broadcastOverflowActions() {
  return [[{ label: 'Reconfigure', icon: 'i-lucide-settings' }], [{ label: 'Remove', icon: 'i-lucide-trash-2', color: 'error' as const }]]
}

// Structured, labeled facts for the Broadcast operational region.
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
    { label: 'Format', value: b.formLabel }
  ]
}

const { copy } = useClipboard()
const copiedId = ref<string | null>(null)

function copyPublishedUrl(broadcast: BroadcastFixture) {
  if (!broadcast.publishedUrl) return
  copy(broadcast.publishedUrl)
  copiedId.value = broadcast.id
  setTimeout(() => {
    if (copiedId.value === broadcast.id) copiedId.value = null
  }, 1500)
}

// Items: a separate status vocabulary from Stash/Input/Broadcast (ready/
// processing/queued/needs-attention describe one preserved media item's
// pipeline state, not an ongoing source or output's health) but the same
// dot + coloured text convention.
const itemStatusMeta: Record<ItemStatus, { label: string, dot: string, text: string, progressStatus: 'active' | 'complete' | 'queued' | 'failed' }> = {
  ready: { label: 'ready', dot: 'bg-success', text: 'text-success', progressStatus: 'complete' },
  processing: { label: 'processing', dot: 'bg-primary', text: 'text-primary', progressStatus: 'active' },
  queued: { label: 'queued', dot: 'bg-neutral-400', text: 'text-dimmed', progressStatus: 'queued' },
  'needs-attention': { label: 'needs attention', dot: 'bg-error', text: 'text-error', progressStatus: 'failed' }
}

const itemSearch = ref('')
const itemStatusFilter = ref<'all' | ItemStatus>('all')
const itemStatusFilterOptions = [
  { label: 'All statuses', value: 'all' },
  { label: 'Ready', value: 'ready' },
  { label: 'Processing', value: 'processing' },
  { label: 'Queued', value: 'queued' },
  { label: 'Needs attention', value: 'needs-attention' }
]

const filteredItems = computed(() => {
  const query = itemSearch.value.trim().toLowerCase()
  return items.value.filter((item) => {
    if (itemStatusFilter.value !== 'all' && item.status !== itemStatusFilter.value) return false
    if (query && !item.title.toLowerCase().includes(query)) return false
    return true
  })
})

function clearItemFilters() {
  itemSearch.value = ''
  itemStatusFilter.value = 'all'
}

const itemsPageSize = 20
const itemsPage = ref(1)

// Search/filter changing the result set invalidates whatever page we were
// on — always land back on page 1 rather than risk an out-of-range page.
watch([itemSearch, itemStatusFilter], () => { itemsPage.value = 1 })

const itemsTotalPages = computed(() => Math.max(1, Math.ceil(filteredItems.value.length / itemsPageSize)))

const paginatedItems = computed(() => {
  const start = (itemsPage.value - 1) * itemsPageSize
  return filteredItems.value.slice(start, start + itemsPageSize)
})

const itemsRangeLabel = computed(() => {
  const total = filteredItems.value.length
  if (total === 0) return ''
  const start = (itemsPage.value - 1) * itemsPageSize + 1
  const end = Math.min(itemsPage.value * itemsPageSize, total)
  return `${start}–${end} of ${total.toLocaleString()}`
})

function itemOverflowActions(item: ItemFixture) {
  const contextual = item.status === 'needs-attention' ? [{ label: 'Retry', icon: 'i-lucide-refresh-cw' }] : []
  return [[{ label: 'View details', icon: 'i-lucide-eye' }, ...contextual], [{ label: 'Remove', icon: 'i-lucide-trash-2', color: 'error' as const }]]
}

function absoluteTime(iso: string) {
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

// Published is when the source item went live — an absolute date, not
// operational recency (that's what checked/synced/rebuilt/updated are for).
function publishedDate(iso: string) {
  return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

function itemThumbnail(item: ItemFixture) {
  return h('div', { class: ['flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-gradient-to-br sm:w-16', item.art ?? 'bg-elevated'] }, [
    h(resolveComponent('UIcon'), { name: 'i-lucide-play', class: 'size-3.5 text-dimmed' })
  ])
}

function itemStatusCell(item: ItemFixture) {
  const meta = itemStatusMeta[item.status]
  const badge = h('span', { class: 'inline-flex items-center gap-1.5' }, [
    h('span', { class: ['size-1.5 rounded-full', meta.dot] }),
    h('span', { class: ['text-xs', meta.text] }, meta.label)
  ])
  if (item.status !== 'processing') return badge
  return h('div', { class: 'space-y-1' }, [
    badge,
    h(OperationProgress, { variant: 'compact', percent: item.progressPercent ?? null, status: meta.progressStatus, class: 'w-24' })
  ])
}

const itemColumns: TableColumn<ItemFixture>[] = [
  {
    accessorKey: 'title',
    header: 'Title',
    cell: ({ row }) => h('div', { class: 'flex items-center gap-2.5' }, [
      itemThumbnail(row.original),
      h(resolveComponent('UTooltip'), { text: row.original.title }, () =>
        h('span', { class: 'block max-w-[280px] truncate font-mono text-sm text-highlighted' }, row.original.title))
    ])
  },
  {
    accessorKey: 'publishedAt',
    header: 'Published',
    cell: ({ row }) => h(resolveComponent('UTooltip'), { text: absoluteTime(row.original.publishedAt) }, () =>
      h('time', { datetime: row.original.publishedAt, class: 'whitespace-nowrap font-mono text-xs text-dimmed' }, publishedDate(row.original.publishedAt)))
  },
  {
    accessorKey: 'duration',
    header: 'Duration',
    cell: ({ row }) => h('span', { class: 'font-mono text-xs text-muted' }, row.original.duration)
  },
  {
    accessorKey: 'sizeLabel',
    header: 'Size',
    cell: ({ row }) => h('span', { class: 'font-mono text-xs text-muted' }, row.original.sizeLabel)
  },
  {
    accessorKey: 'status',
    header: 'Status',
    cell: ({ row }) => itemStatusCell(row.original)
  },
  {
    id: 'actions',
    cell: ({ row }) => h(resolveComponent('UDropdownMenu'), { items: itemOverflowActions(row.original) },
      () => h(resolveComponent('UButton'), { icon: 'i-lucide-ellipsis-vertical', ariaLabel: 'More actions', title: 'More actions', variant: 'ghost', color: 'neutral', size: 'sm' }))
  }
]

// Standard desktop table surface: one continuous bg-muted panel with a
// subtle border, an integrated bg-elevated header strip, and rows divided
// (not carded) within it — see planning/DECISIONS.md, "Desktop table surface".
const itemTableUi = {
  thead: 'bg-elevated/60',
  th: 'font-mono text-xs uppercase tracking-wider text-dimmed py-2 px-3',
  td: 'py-2 px-3',
  tbody: 'divide-y divide-default/60'
}
</script>

<template>
  <main v-if="stash" class="mx-auto max-w-4xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink to="/stashes" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Stashes
    </RouterLink>

    <!-- Stash header: identity stands alone, status directly beneath it, facts secondary -->
    <header class="flex items-start justify-between gap-4">
      <div class="flex items-start gap-4">
        <div class="flex size-14 shrink-0 items-center justify-center rounded-md bg-elevated font-mono text-lg text-muted">
          {{ monogram(stash.name) }}
        </div>
        <div class="min-w-0">
          <h1 class="truncate font-mono text-2xl leading-tight text-highlighted">{{ stash.name }}</h1>
          <div class="mt-2 flex items-center gap-1.5">
            <span class="size-1.5 rounded-full" :class="statusMeta[stash.status].dot" />
            <span class="text-xs" :class="statusMeta[stash.status].text">{{ statusMeta[stash.status].label }}</span>
          </div>
          <p class="mt-1 text-sm text-muted">{{ stash.itemCount.toLocaleString() }} items · {{ stash.sizeLabel }} · updated {{ stash.lastActivity }}</p>

          <div v-if="stash.operation" class="mt-3 max-w-md">
            <OperationProgress
              variant="compact"
              :label="stash.operation.label"
              :percent="stash.operation.percent"
              :stage="stash.operation.stage"
              status="active"
            />
          </div>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <UButton label="Rebuild" icon="i-lucide-refresh-cw" variant="subtle" color="neutral" size="sm" class="hidden sm:flex" />
        <UDropdownMenu :items="stashActions">
          <UButton icon="i-lucide-ellipsis-vertical" aria-label="More actions" title="More actions" variant="ghost" color="neutral" size="md" />
        </UDropdownMenu>
      </div>
    </header>

    <USeparator />

    <!-- Inputs: confident section heading, compact rows -->
    <section class="space-y-3">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-baseline gap-2">
          <h2 class="text-base font-medium text-highlighted">Inputs</h2>
          <span class="font-mono text-xs text-dimmed">{{ inputs.length }}</span>
        </div>
        <UButton label="Add input" icon="i-lucide-plus" variant="ghost" color="neutral" size="sm" @click="openAddInput" />
      </div>

      <div v-for="input in inputs" :key="input.id" class="flex items-center gap-3 rounded-md bg-muted p-3">
        <UIcon :name="providerIcon[input.provider]" class="size-4 shrink-0 text-muted" />
        <div class="min-w-0 flex-1">
          <p class="truncate font-mono text-sm text-highlighted">{{ input.identity }}</p>
          <p class="mt-0.5 flex items-center gap-1.5 text-xs text-dimmed">
            <span class="size-1.5 rounded-full" :class="statusMeta[input.status].dot" />
            <span :class="statusMeta[input.status].text">{{ statusMeta[input.status].label }}</span>
            · {{ input.providerLabel }} · checked {{ input.lastChecked }}
            <template v-if="activeFilterCount(input.filters) > 0">· {{ activeFilterCount(input.filters) }} filters</template>
          </p>
        </div>
        <UButton icon="i-lucide-refresh-cw" aria-label="Sync now" title="Sync now" variant="ghost" color="neutral" size="sm" />
        <UDropdownMenu :items="inputOverflowActions">
          <UButton icon="i-lucide-ellipsis-vertical" aria-label="More actions" title="More actions" variant="ghost" color="neutral" size="sm" />
        </UDropdownMenu>
      </div>
    </section>

    <!-- Add Input: a small contextual modal, not a page. -->
    <UModal v-model:open="addInputOpen" title="Add input" description="Add another source for this stash to preserve." :ui="{ content: 'max-w-md' }">
      <template #body>
        <UForm :state="addInputForm" :validate="validateAddInputForm" class="space-y-5" @submit="onAddInputSubmit">
          <UFormField name="sourceUrl" label="Source URL">
            <UInput
              v-model="addInputForm.sourceUrl"
              placeholder="https://youtube.com/@channel"
              icon="i-lucide-link"
              class="w-full font-mono"
              size="lg"
              autofocus
            />
            <p v-if="addInputDetected" class="mt-1.5 flex items-center gap-1.5 text-xs text-success">
              <UIcon :name="addInputDetected.icon" class="size-3.5" />
              {{ addInputDetected.providerLabel }} · {{ addInputDetected.identity }}
            </p>
          </UFormField>

          <UCollapsible v-model:open="addInputFiltersOpen">
            <UButton
              :label="addInputActiveFilterCount > 0 ? `Filters · ${addInputActiveFilterCount} configured` : 'Filters'"
              :trailing-icon="addInputFiltersOpen ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              variant="ghost"
              color="neutral"
              size="sm"
            />
            <template #content>
              <div class="mt-3 space-y-4">
                <UFormField
                  name="titleRegexInclude"
                  label="Only include titles matching"
                  help="Regular expression — leave blank to include everything."
                >
                  <UInput v-model="addInputForm.titleRegexInclude" placeholder="e.g. ^S3E" class="w-full font-mono" />
                </UFormField>

                <UFormField
                  name="titleRegexExclude"
                  label="Exclude titles matching"
                  help="Regular expression — leave blank to exclude nothing."
                >
                  <UInput v-model="addInputForm.titleRegexExclude" placeholder="e.g. shorts" class="w-full font-mono" />
                </UFormField>

                <div v-if="addInputDetected?.provider === 'youtube-channel'" class="space-y-2">
                  <UCheckbox v-model="addInputForm.includeShorts" label="Include Shorts" />
                  <UCheckbox v-model="addInputForm.includeLive" label="Include live broadcasts and premieres" />
                </div>

                <UButton
                  v-if="addInputActiveFilterCount > 0"
                  label="Clear filters"
                  variant="ghost"
                  color="neutral"
                  size="xs"
                  @click="clearAddInputFilters"
                />
              </div>
            </template>
          </UCollapsible>

          <PreflightSummary v-if="inputPreflight" :state="inputPreflight" />

          <div class="space-y-2">
            <div class="flex items-center justify-end gap-2">
              <UButton label="Cancel" variant="ghost" color="neutral" @click="addInputOpen = false" />
              <UButton :label="addInputCommitLabel" type="submit" />
            </div>
            <p v-if="inputPreflight?.status === 'analyzing'" class="text-right text-xs text-dimmed">Storage estimate is still being calculated.</p>
          </div>
        </UForm>
      </template>
    </UModal>

    <USeparator />

    <!-- Broadcasts: confident heading, two-tier cards with structured facts -->
    <section class="space-y-3">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-baseline gap-2">
          <h2 class="text-base font-medium text-highlighted">Broadcasts</h2>
          <span class="font-mono text-xs text-dimmed">{{ broadcasts.length }}</span>
        </div>
        <UButton label="New broadcast" icon="i-lucide-plus" variant="ghost" color="neutral" size="sm" :to="`/stashes/${stash?.id}/broadcasts/new`" />
      </div>

      <div v-for="broadcast in broadcasts" :key="broadcast.id" class="rounded-md bg-muted p-3.5">
        <!-- Tier 1: recognize -->
        <div class="flex items-center gap-3">
          <UIcon :name="broadcastIcon[broadcast.kind]" class="size-4 shrink-0 text-muted" />
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
            icon="i-lucide-refresh-cw"
            variant="subtle"
            color="neutral"
            size="sm"
          />
          <UDropdownMenu :items="broadcastOverflowActions()">
            <UButton icon="i-lucide-ellipsis-vertical" aria-label="More actions" title="More actions" variant="ghost" color="neutral" size="sm" />
          </UDropdownMenu>
        </div>

        <!-- Tier 2: inspect — inset operational region, distinct surface -->
        <div class="mt-3 rounded-md bg-elevated p-3">
          <OperationProgress
            v-if="broadcast.buildState === 'rebuilding'"
            variant="compact"
            label="Rebuilding"
            :percent="broadcast.buildPercent ?? null"
            :stage="broadcast.buildStage"
            status="active"
          />

          <!-- Structured facts: stacked key/value rows by default (phone-first),
               becoming a horizontal wrap of label-over-value blocks at sm+. -->
          <div v-else class="flex flex-col gap-1.5 sm:flex-row sm:flex-wrap sm:gap-x-6 sm:gap-y-2">
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
                variant="soft"
                size="xs"
                @click="copyPublishedUrl(broadcast)"
              />
              <UButton
                icon="i-lucide-external-link"
                aria-label="Open feed"
                title="Open feed"
                :to="broadcast.publishedUrl"
                target="_blank"
                variant="ghost"
                color="neutral"
                size="xs"
              />
            </div>
            <p class="text-xs text-dimmed">Anyone with this link can access the feed — treat it like a password.</p>
          </div>
        </div>
      </div>
    </section>

    <USeparator />

    <!-- Items: preserved media collection -->
    <section class="space-y-3">
      <div class="flex items-baseline gap-2">
        <h2 class="text-base font-medium text-highlighted">Items</h2>
        <span class="font-mono text-xs text-dimmed">{{ items.length }}</span>
      </div>

      <div class="flex flex-col gap-2 sm:flex-row">
        <UInput v-model="itemSearch" placeholder="Search items" icon="i-lucide-search" class="sm:max-w-sm sm:flex-1" />
        <USelect v-model="itemStatusFilter" :items="itemStatusFilterOptions" value-key="value" class="sm:w-40" />
      </div>

      <template v-if="filteredItems.length > 0">
        <div class="hidden overflow-hidden rounded-md border border-default bg-muted md:block">
          <UTable :data="paginatedItems" :columns="itemColumns" :ui="itemTableUi" class="text-sm" />
        </div>

        <!-- UTable is a literal <table>; it can't reflow into a card shape.
             Below md, render the same fixture as the same compact row pattern
             used by Inputs/Broadcasts rather than forcing horizontal scroll. -->
        <div class="space-y-2 md:hidden">
          <div v-for="item in paginatedItems" :key="item.id" class="flex items-center gap-3 rounded-md bg-muted p-3">
            <div class="flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-gradient-to-br" :class="item.art ?? 'bg-elevated'">
              <UIcon name="i-lucide-play" class="size-3.5 text-dimmed" />
            </div>
            <div class="min-w-0 flex-1 space-y-1">
              <p class="truncate font-mono text-sm text-highlighted">{{ item.title }}</p>
              <div class="flex items-center gap-1.5">
                <span class="size-1.5 rounded-full" :class="itemStatusMeta[item.status].dot" />
                <span class="text-xs" :class="itemStatusMeta[item.status].text">{{ itemStatusMeta[item.status].label }}</span>
              </div>
              <OperationProgress
                v-if="item.status === 'processing'"
                variant="compact"
                :percent="item.progressPercent ?? null"
                :status="itemStatusMeta[item.status].progressStatus"
                class="w-24"
              />
              <p class="font-mono text-xs text-dimmed">
                <UTooltip :text="absoluteTime(item.publishedAt)">
                  <time :datetime="item.publishedAt">{{ publishedDate(item.publishedAt) }}</time>
                </UTooltip>
                · {{ item.duration }} · {{ item.sizeLabel }}
              </p>
            </div>
            <UDropdownMenu :items="itemOverflowActions(item)">
              <UButton icon="i-lucide-ellipsis-vertical" aria-label="More actions" title="More actions" variant="ghost" color="neutral" size="sm" />
            </UDropdownMenu>
          </div>
        </div>

        <!-- Pagination: quiet positional text + page control, subordinate to the collection itself. -->
        <div class="hidden items-center justify-between gap-3 sm:flex">
          <p class="font-mono text-xs text-dimmed">{{ itemsRangeLabel }}</p>
          <UPagination v-if="itemsTotalPages > 1" v-model:page="itemsPage" :total="filteredItems.length" :items-per-page="itemsPageSize" size="sm" />
        </div>

        <!-- Phone: a numbered page strip doesn't fit 390px — Previous/Next + "page X of Y" instead. -->
        <div class="flex items-center justify-between gap-3 sm:hidden">
          <UButton label="Previous" icon="i-lucide-chevron-left" variant="ghost" color="neutral" size="sm" :disabled="itemsPage <= 1" @click="itemsPage--" />
          <p class="font-mono text-xs text-dimmed">{{ itemsPage }} / {{ itemsTotalPages }}</p>
          <UButton label="Next" trailing-icon="i-lucide-chevron-right" variant="ghost" color="neutral" size="sm" :disabled="itemsPage >= itemsTotalPages" @click="itemsPage++" />
        </div>
      </template>

      <!-- No-results: search/filter produced nothing — not the Stash's true empty state. -->
      <div v-else class="rounded-md bg-muted p-4 text-center">
        <p class="text-sm text-muted">No items match these filters.</p>
        <UButton label="Clear filters" variant="ghost" color="neutral" size="sm" class="mt-2" @click="clearItemFilters" />
      </div>
    </section>
  </main>

  <main v-else class="mx-auto max-w-4xl px-4 py-8 sm:px-8">
    <p class="text-sm text-muted">Stash not found.</p>
  </main>
</template>
