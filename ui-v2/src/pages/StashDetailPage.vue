<script setup lang="ts">
import { computed, h, onMounted, ref, resolveComponent, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useClipboard } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'

import { fetchStashBroadcasts } from '../api/broadcasts'
import { fetchStashInputs } from '../api/inputs'
import { fetchStash, fetchStashItems } from '../api/stashes'
import type { BroadcastApiResource } from '../types/broadcast-plugin'
import type { StashInputApiResource } from '../types/input'
import type { StashItemApiResource, StashItemsApiResponse } from '../types/item'
import type { StashApiResource } from '../types/stash'

const route = useRoute()
const { copy } = useClipboard()

const stash = ref<StashApiResource>()
const inputs = ref<StashInputApiResource[]>([])
const broadcasts = ref<BroadcastApiResource[]>([])
const items = ref<StashItemsApiResponse>({ items: [], total: 0, limit: 20, offset: 0, stash_item_count: 0 })
const loading = ref(true)
const itemsLoading = ref(false)
const error = ref<string>()
const inputsError = ref<string>()
const broadcastsError = ref<string>()
const itemsError = ref<string>()
const copiedId = ref<string>()

const stateMeta: Record<string, { label: string, dot: string, text: string }> = {
  ready: { label: 'ready', dot: 'bg-success', text: 'text-success' },
  processing: { label: 'processing', dot: 'bg-primary', text: 'text-primary' },
  pending: { label: 'pending', dot: 'bg-neutral-400', text: 'text-dimmed' },
  stale: { label: 'stale', dot: 'bg-warning', text: 'text-warning' },
  failed: { label: 'failed', dot: 'bg-error', text: 'text-error' },
  disabled: { label: 'disabled', dot: 'bg-neutral-400', text: 'text-dimmed' },
  discovered: { label: 'discovered', dot: 'bg-neutral-400', text: 'text-dimmed' },
  metadata_ready: { label: 'metadata ready', dot: 'bg-neutral-400', text: 'text-dimmed' },
  download_pending: { label: 'download pending', dot: 'bg-neutral-400', text: 'text-dimmed' },
  downloading: { label: 'downloading', dot: 'bg-primary', text: 'text-primary' },
  ignored: { label: 'ignored', dot: 'bg-neutral-400', text: 'text-dimmed' },
  missing: { label: 'missing', dot: 'bg-error', text: 'text-error' }
}

function statePresentation(state: string) {
  return stateMeta[state] ?? { label: state.replaceAll('_', ' '), dot: 'bg-neutral-400', text: 'text-dimmed' }
}

function monogram(name: string) {
  return name.charAt(0).toUpperCase()
}

function absoluteTime(value?: string | null) {
  return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
}

function relativeDate(value?: string | null) {
  return value ? new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : ''
}

function inputTitle(input: StashInputApiResource) {
  return input.title || input.provider_input_id || input.source_uri
}

function inputFilterCount(input: StashInputApiResource) {
  return [input.options?.title_regex_include, input.options?.title_regex_exclude].filter(value => value !== null && value !== undefined && value !== '').length
}

function broadcastFacts(broadcast: BroadcastApiResource) {
  return [
    { label: 'Type', value: broadcast.type },
    ...(broadcast.last_built_at ? [{ label: 'Last built', value: relativeDate(broadcast.last_built_at) }] : [])
  ]
}

function copyPublishedUrl(broadcast: BroadcastApiResource) {
  if (!broadcast.published_url) return
  copy(broadcast.published_url)
  copiedId.value = broadcast.id
  setTimeout(() => {
    if (copiedId.value === broadcast.id) copiedId.value = undefined
  }, 1500)
}

function itemTitle(item: StashItemApiResource) {
  return item.display_title || item.media_item?.title || 'Untitled item'
}

function itemState(item: StashItemApiResource) {
  return item.media_item?.state ?? item.state
}

function itemDuration(item: StashItemApiResource) {
  const seconds = item.media_item?.duration_seconds
  if (seconds === null || seconds === undefined) return '—'
  const minutes = Math.floor(seconds / 60)
  return `${minutes}:${String(seconds % 60).padStart(2, '0')}`
}

function itemSize(item: StashItemApiResource) {
  const bytes = item.total_asset_size_bytes
  if (bytes === null || bytes === undefined) return '—'
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

function itemThumbnail() {
  return h('div', { class: 'flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-elevated sm:w-16' }, [
    h(resolveComponent('UIcon'), { name: 'i-lucide-play', class: 'size-3.5 text-dimmed' })
  ])
}

function itemStatusCell(item: StashItemApiResource) {
  const meta = statePresentation(itemState(item))
  return h('span', { class: 'inline-flex items-center gap-1.5' }, [
    h('span', { class: ['size-1.5 rounded-full', meta.dot] }),
    h('span', { class: ['text-xs', meta.text] }, meta.label)
  ])
}

const itemColumns: TableColumn<StashItemApiResource>[] = [
  {
    accessorKey: 'id',
    header: 'Title',
    cell: ({ row }) => h('div', { class: 'flex items-center gap-2.5' }, [
      itemThumbnail(),
      h(resolveComponent('UTooltip'), { text: itemTitle(row.original) }, () =>
        h('span', { class: 'block max-w-[280px] truncate font-mono text-sm text-highlighted' }, itemTitle(row.original)))
    ])
  },
  {
    id: 'published',
    header: 'Published',
    cell: ({ row }) => row.original.media_item?.published_at
      ? h(resolveComponent('UTooltip'), { text: absoluteTime(row.original.media_item.published_at) }, () =>
        h('time', { datetime: row.original.media_item?.published_at, class: 'whitespace-nowrap font-mono text-xs text-dimmed' }, relativeDate(row.original.media_item?.published_at)))
      : h('span', { class: 'font-mono text-xs text-dimmed' }, '—')
  },
  { id: 'duration', header: 'Duration', cell: ({ row }) => h('span', { class: 'font-mono text-xs text-muted' }, itemDuration(row.original)) },
  { id: 'size', header: 'Size', cell: ({ row }) => h('span', { class: 'font-mono text-xs text-muted' }, itemSize(row.original)) },
  { id: 'status', header: 'Status', cell: ({ row }) => itemStatusCell(row.original) }
]

const itemTableUi = {
  thead: 'bg-elevated/60',
  th: 'font-mono text-xs uppercase tracking-wider text-dimmed py-2 px-3',
  td: 'py-2 px-3',
  tbody: 'divide-y divide-default/60'
}

const itemSearch = ref('')
const itemStatusFilter = ref('all')
const itemStatusFilterOptions = [
  { label: 'All statuses', value: 'all' },
  { label: 'Ready', value: 'ready' },
  { label: 'Downloading', value: 'downloading' },
  { label: 'Pending', value: 'download_pending' },
  { label: 'Failed', value: 'failed' },
  { label: 'Ignored', value: 'ignored' }
]
const itemsPageSize = 20
const itemsPage = ref(1)
const itemsTotalPages = computed(() => Math.max(1, Math.ceil(items.value.total / itemsPageSize)))
const itemsRangeLabel = computed(() => {
  if (items.value.total === 0) return ''
  const start = items.value.offset + 1
  const end = Math.min(items.value.offset + items.value.items.length, items.value.total)
  return `${start}–${end} of ${items.value.total.toLocaleString()}`
})

function clearItemFilters() {
  itemSearch.value = ''
  itemStatusFilter.value = 'all'
}

async function loadItems() {
  const stashId = String(route.params.id)
  if (!stash.value || !stashId) return

  itemsLoading.value = true
  itemsError.value = undefined

  try {
    items.value = await fetchStashItems(stashId, {
      limit: itemsPageSize,
      offset: (itemsPage.value - 1) * itemsPageSize,
      search: itemSearch.value.trim() || undefined,
      status: itemStatusFilter.value === 'all' ? undefined : itemStatusFilter.value
    })
  } catch (exception) {
    itemsError.value = exception instanceof Error ? exception.message : 'Could not load Stash items.'
  } finally {
    itemsLoading.value = false
  }
}

async function load() {
  const stashId = String(route.params.id)
  loading.value = true
  error.value = undefined
  inputsError.value = undefined
  broadcastsError.value = undefined
  itemsError.value = undefined
  stash.value = undefined
  inputs.value = []
  broadcasts.value = []
  items.value = { items: [], total: 0, limit: itemsPageSize, offset: 0, stash_item_count: 0 }
  itemsPage.value = 1

  try {
    stash.value = await fetchStash(stashId)
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not load this Stash.'
    loading.value = false
    return
  }

  const [inputResult, broadcastResult] = await Promise.allSettled([
    fetchStashInputs(stashId),
    fetchStashBroadcasts(stashId)
  ])

  if (inputResult.status === 'fulfilled') inputs.value = inputResult.value
  else inputsError.value = inputResult.reason instanceof Error ? inputResult.reason.message : 'Could not load Inputs.'

  if (broadcastResult.status === 'fulfilled') broadcasts.value = broadcastResult.value
  else broadcastsError.value = broadcastResult.reason instanceof Error ? broadcastResult.reason.message : 'Could not load Broadcasts.'

  loading.value = false
  await loadItems()
}

watch([itemSearch, itemStatusFilter], () => { itemsPage.value = 1 })
watch([itemSearch, itemStatusFilter, itemsPage], () => { void loadItems() })
watch(() => route.params.id, () => { void load() })
onMounted(load)
</script>

<template>
  <main v-if="loading" class="mx-auto max-w-4xl px-4 py-8 sm:px-8">
    <div class="flex items-center gap-2 text-sm text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
      Loading Stash…
    </div>
  </main>

  <main v-else-if="error" class="mx-auto max-w-4xl space-y-4 px-4 py-8 sm:px-8">
    <RouterLink to="/stashes" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Stashes
    </RouterLink>
    <UAlert color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load Stash" :description="error" />
  </main>

  <main v-else-if="stash" class="mx-auto max-w-4xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink to="/stashes" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Stashes
    </RouterLink>

    <header class="flex items-start justify-between gap-4">
      <div class="flex items-start gap-4">
        <div class="flex size-14 shrink-0 items-center justify-center rounded-md bg-elevated font-mono text-lg text-muted">
          {{ monogram(stash.name) }}
        </div>
        <div class="min-w-0">
          <h1 class="truncate font-mono text-2xl leading-tight text-highlighted">{{ stash.name }}</h1>
          <div class="mt-2 flex items-center gap-1.5">
            <span class="size-1.5 rounded-full" :class="statePresentation(stash.state).dot" />
            <span class="text-xs" :class="statePresentation(stash.state).text">{{ statePresentation(stash.state).label }}</span>
          </div>
          <p class="mt-1 text-sm text-muted">{{ items.stash_item_count.toLocaleString() }} items<span v-if="stash.updated_at"> · updated {{ relativeDate(stash.updated_at) }}</span></p>
          <p v-if="stash.description" class="mt-2 max-w-md text-sm text-muted">{{ stash.description }}</p>
        </div>
      </div>
    </header>

    <USeparator />

    <section class="space-y-3">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-baseline gap-2">
          <h2 class="text-base font-medium text-highlighted">Inputs</h2>
          <span class="font-mono text-xs text-dimmed">{{ inputs.length }}</span>
        </div>
      </div>

      <UAlert v-if="inputsError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load Inputs" :description="inputsError" />

      <template v-else-if="inputs.length">
        <div v-for="input in inputs" :key="input.id" class="flex items-center gap-3 rounded-md bg-muted p-3">
          <UIcon name="i-lucide-link" class="size-4 shrink-0 text-muted" />
          <div class="min-w-0 flex-1">
            <p class="truncate font-mono text-sm text-highlighted">{{ inputTitle(input) }}</p>
            <p class="mt-0.5 truncate font-mono text-xs text-dimmed">{{ input.source_uri }}</p>
            <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-dimmed">
              <span class="size-1.5 rounded-full" :class="statePresentation(input.state).dot" />
              <span :class="statePresentation(input.state).text">{{ statePresentation(input.state).label }}</span>
              <span>· {{ input.provider_key }}</span>
              <span v-if="input.sync_mode">· {{ input.sync_mode }}</span>
              <span v-if="input.last_checked_at">· checked {{ relativeDate(input.last_checked_at) }}</span>
              <span v-if="inputFilterCount(input)">· {{ inputFilterCount(input) }} filters</span>
            </p>
          </div>
          <UButton label="Configure" icon="i-lucide-settings" :to="`/stashes/${stash.id}/inputs/${input.id}/configure`" variant="ghost" color="neutral" size="sm" />
        </div>
      </template>

      <div v-else class="rounded-md bg-muted p-4 text-center">
        <p class="text-sm text-muted">No Inputs configured for this Stash.</p>
      </div>
    </section>

    <USeparator />

    <section class="space-y-3">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-baseline gap-2">
          <h2 class="text-base font-medium text-highlighted">Broadcasts</h2>
          <span class="font-mono text-xs text-dimmed">{{ broadcasts.length }}</span>
        </div>
        <UButton label="New broadcast" icon="i-lucide-plus" variant="ghost" color="neutral" size="sm" :to="`/stashes/${stash.id}/broadcasts/new`" />
      </div>

      <UAlert v-if="broadcastsError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load Broadcasts" :description="broadcastsError" />

      <template v-else-if="broadcasts.length">
        <div v-for="broadcast in broadcasts" :key="broadcast.id" class="rounded-md bg-muted p-3.5">
          <div class="flex items-center gap-3">
            <UIcon name="i-lucide-radio" class="size-4 shrink-0 text-muted" />
            <div class="min-w-0 flex-1">
              <p class="truncate font-mono text-sm text-highlighted">{{ broadcast.name }}</p>
              <p class="mt-0.5 flex items-center gap-1.5 text-xs" :class="statePresentation(broadcast.state).text">
                <span class="size-1.5 rounded-full" :class="statePresentation(broadcast.state).dot" />
                {{ statePresentation(broadcast.state).label }}
              </p>
            </div>
            <UButton label="Details" icon="i-lucide-arrow-up-right" :to="`/broadcasts/${broadcast.id}`" variant="subtle" color="neutral" size="sm" />
          </div>

          <div class="mt-3 rounded-md bg-elevated p-3">
            <div class="flex flex-col gap-1.5 sm:flex-row sm:flex-wrap sm:gap-x-6 sm:gap-y-2">
              <div v-for="fact in broadcastFacts(broadcast)" :key="fact.label" class="flex items-center justify-between gap-3 sm:block sm:justify-normal">
                <p class="shrink-0 font-mono text-[10px] uppercase tracking-wider text-dimmed sm:mb-1">{{ fact.label }}</p>
                <p class="truncate font-mono text-xs text-toned">{{ fact.value }}</p>
              </div>
            </div>

            <div v-if="broadcast.published_url" class="mt-3 space-y-1.5">
              <div class="flex items-center gap-2 rounded-md bg-accented p-2">
                <UIcon name="i-lucide-link" class="size-3.5 shrink-0 text-dimmed" />
                <p class="min-w-0 flex-1 truncate font-mono text-xs text-muted">{{ broadcast.published_url }}</p>
                <UButton :label="copiedId === broadcast.id ? 'Copied' : 'Copy'" :icon="copiedId === broadcast.id ? 'i-lucide-check' : 'i-lucide-copy'" :color="copiedId === broadcast.id ? 'success' : 'neutral'" variant="soft" size="xs" @click="copyPublishedUrl(broadcast)" />
                <UButton icon="i-lucide-external-link" aria-label="Open published link" title="Open published link" :to="broadcast.published_url" target="_blank" variant="ghost" color="neutral" size="xs" />
              </div>
            </div>
          </div>
        </div>
      </template>

      <div v-else class="rounded-md bg-muted p-4 text-center">
        <p class="text-sm text-muted">No Broadcasts configured for this Stash.</p>
        <UButton label="New broadcast" :to="`/stashes/${stash.id}/broadcasts/new`" variant="ghost" color="neutral" size="sm" class="mt-2" />
      </div>
    </section>

    <USeparator />

    <section class="space-y-3">
      <div class="flex items-baseline gap-2">
        <h2 class="text-base font-medium text-highlighted">Items</h2>
        <span class="font-mono text-xs text-dimmed">{{ items.stash_item_count }}</span>
      </div>

      <div class="flex flex-col gap-2 sm:flex-row">
        <UInput v-model="itemSearch" placeholder="Search items" icon="i-lucide-search" class="sm:max-w-sm sm:flex-1" />
        <USelect v-model="itemStatusFilter" :items="itemStatusFilterOptions" value-key="value" class="sm:w-40" />
      </div>

      <UAlert v-if="itemsError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load Items" :description="itemsError" />
      <div v-else-if="itemsLoading" class="flex items-center gap-2 rounded-md bg-muted p-4 text-sm text-muted">
        <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
        Loading items…
      </div>
      <template v-else-if="items.items.length">
        <div class="hidden overflow-hidden rounded-md border border-default bg-muted md:block">
          <UTable :data="items.items" :columns="itemColumns" :ui="itemTableUi" class="text-sm" />
        </div>

        <div class="space-y-2 md:hidden">
          <div v-for="item in items.items" :key="item.id" class="flex items-center gap-3 rounded-md bg-muted p-3">
            <div class="flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-elevated">
              <UIcon name="i-lucide-play" class="size-3.5 text-dimmed" />
            </div>
            <div class="min-w-0 flex-1 space-y-1">
              <p class="truncate font-mono text-sm text-highlighted">{{ itemTitle(item) }}</p>
              <div class="flex items-center gap-1.5">
                <span class="size-1.5 rounded-full" :class="statePresentation(itemState(item)).dot" />
                <span class="text-xs" :class="statePresentation(itemState(item)).text">{{ statePresentation(itemState(item)).label }}</span>
              </div>
              <p class="font-mono text-xs text-dimmed">
                <time v-if="item.media_item?.published_at" :datetime="item.media_item.published_at">{{ relativeDate(item.media_item.published_at) }}</time>
                <template v-else>—</template>
                · {{ itemDuration(item) }} · {{ itemSize(item) }}
              </p>
            </div>
          </div>
        </div>

        <div class="hidden items-center justify-between gap-3 sm:flex">
          <p class="font-mono text-xs text-dimmed">{{ itemsRangeLabel }}</p>
          <UPagination v-if="itemsTotalPages > 1" v-model:page="itemsPage" :total="items.total" :items-per-page="itemsPageSize" size="sm" />
        </div>

        <div class="flex items-center justify-between gap-3 sm:hidden">
          <UButton label="Previous" icon="i-lucide-chevron-left" variant="ghost" color="neutral" size="sm" :disabled="itemsPage <= 1" @click="itemsPage--" />
          <p class="font-mono text-xs text-dimmed">{{ itemsPage }} / {{ itemsTotalPages }}</p>
          <UButton label="Next" trailing-icon="i-lucide-chevron-right" variant="ghost" color="neutral" size="sm" :disabled="itemsPage >= itemsTotalPages" @click="itemsPage++" />
        </div>
      </template>

      <div v-else class="rounded-md bg-muted p-4 text-center">
        <p class="text-sm text-muted">{{ itemSearch || itemStatusFilter !== 'all' ? 'No items match these filters.' : 'No items in this Stash.' }}</p>
        <UButton v-if="itemSearch || itemStatusFilter !== 'all'" label="Clear filters" variant="ghost" color="neutral" size="sm" class="mt-2" @click="clearItemFilters" />
      </div>
    </section>
  </main>
</template>
