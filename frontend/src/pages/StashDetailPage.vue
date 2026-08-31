<script setup lang="ts">
import { computed, h, onBeforeUnmount, onMounted, ref, resolveComponent, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useClipboard } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'

import { fetchStashBroadcasts, rebuildBroadcast } from '../api/broadcasts'
import { fetchStashInputs, syncStashInput } from '../api/inputs'
import { deleteStash, fetchStash, fetchStashDeleteImpact, fetchStashItems, retryFailedStash, updateStash, type UpdateStashInput } from '../api/stashes'
import { subscribeLiveUpdates, type JobLiveEvent, type LiveEvent } from '../live/mercure'
import type { BroadcastApiResource } from '../types/broadcast-plugin'
import type { CommandOperation } from '../api/commands'
import type { StashInputApiResource } from '../types/input'
import type { StashItemApiResource, StashItemsApiResponse } from '../types/item'
import type { StashApiResource, StashDeleteImpact } from '../types/stash'
import OperationProgress from '../components/OperationProgress.vue'
import { fetchJobs, type JobApiResource } from '../api/status'
import { formatExactDate, formatRelativeDate } from '../utils/formatDate'

const route = useRoute()
const router = useRouter()
const { copy } = useClipboard()

const stash = ref<StashApiResource>()
const inputs = ref<StashInputApiResource[]>([])
const broadcasts = ref<BroadcastApiResource[]>([])
const items = ref<StashItemsApiResponse>({ items: [], total: 0, limit: 20, offset: 0, stash_item_count: 0 })
const jobs = ref<JobApiResource[]>([])
const loading = ref(true)
const itemsLoading = ref(false)
const error = ref<string>()
const inputsError = ref<string>()
const broadcastsError = ref<string>()
const itemsError = ref<string>()
const copiedId = ref<string>()
const actionError = ref<string>()
const refreshError = ref<string>()
const actionNotice = ref<string>()
const editOpen = ref(false)
const editSaving = ref(false)
const editError = ref<string>()
const editForm = ref<UpdateStashInput>({ name: '', description: '', sync_mode: '', download_policy: '', organization_mode: '' })
const deleteOpen = ref(false)
const deleteImpactLoading = ref(false)
const deleteImpact = ref<StashDeleteImpact>()
const deleteConfirming = ref(false)
const retrying = ref(false)
const stashOperation = ref<CommandOperation>()
const inputOperations = ref<Record<string, CommandOperation | undefined>>({})
const broadcastOperations = ref<Record<string, CommandOperation | undefined>>({})
let unsubscribe: (() => void) | undefined
let refreshTimer: ReturnType<typeof setTimeout> | undefined
let loadGeneration = 0
let itemsLoadGeneration = 0

const syncModeOptions = [
  { label: 'Automatic', value: 'automatic' },
  { label: 'Manual', value: 'manual' }
]
const downloadPolicyOptions = [
  { label: 'Video', value: 'video' },
  { label: 'Audio only', value: 'audio_only' },
  { label: 'Metadata only', value: 'metadata_only' },
  { label: 'Manual download', value: 'manual_download' }
]
const organizationModeOptions = [
  { label: 'Flat', value: 'flat' },
  { label: 'Chronological', value: 'chronological' },
  { label: 'Series', value: 'series' },
  { label: 'Series and season', value: 'seasoned_series' }
]

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
  return formatExactDate(value)
}

function relativeDate(value?: string | null) {
  return formatRelativeDate(value)
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

function isTerminal(operation: CommandOperation | undefined) {
  return operation?.state === 'completed' || operation?.state === 'failed' || operation?.state === 'rejected'
}

function operationText(operation: CommandOperation | undefined, verb: string) {
  if (!operation) return undefined
  if (operation.state === 'accepted') return `${verb} queued…`
  if (operation.state === 'running') return operation.label || `${verb}…`
  if (operation.state === 'completed') return `${verb} complete`
  return `${verb} failed. Try again.`
}

const failedItemCount = computed(() => items.value.status_counts?.failed ?? 0)
const activeJobs = computed(() => jobs.value.filter(job => job.entity_type === 'media_item' && job.payload?.stash_id === stash.value?.id && (job.state === 'processing' || job.state === 'pending')))
const downloadOperation = computed(() => {
  const ignored = items.value.ignored_count ?? 0
  const total = Math.max(0, items.value.stash_item_count - ignored)
  const ready = items.value.status_counts?.ready ?? 0
  const pending = (items.value.status_counts?.download_pending ?? 0) + (items.value.status_counts?.downloading ?? 0)
  if (activeJobs.value.length === 0 && pending === 0) return undefined

  const current = activeJobs.value.find(job => job.state === 'processing') ?? activeJobs.value[0]
  const item = current ? items.value.items.find(candidate => candidate.media_item_id === current.entity_id) : undefined
  const stage = current
    ? [...new Set([current.progress_label, item ? itemTitle(item) : undefined].filter((value): value is string => Boolean(value)))].join(' · ') || undefined
    : undefined
  const percent = total === 0 ? 100 : Math.round((ready / total) * 100)
  const count = `${ready} of ${total} items`

  return { percent, stage, count }
})
const itemSort = ref({
  key: typeof route.query.sort === 'string' ? route.query.sort : 'published',
  direction: route.query.dir === 'asc' ? 'asc' as const : 'desc' as const
})

function openEdit() {
  if (!stash.value) return
  editForm.value = {
    name: stash.value.name,
    description: stash.value.description ?? '',
    sync_mode: stash.value.sync_mode ?? '',
    download_policy: stash.value.download_policy ?? '',
    organization_mode: stash.value.organization_mode ?? ''
  }
  editError.value = undefined
  editOpen.value = true
}

async function saveEdit() {
  if (!stash.value || editSaving.value) return
  editSaving.value = true
  editError.value = undefined

  try {
    stash.value = await updateStash(stash.value.id, editForm.value)
    editOpen.value = false
    actionNotice.value = 'Stash updated.'
  } catch (exception) {
    editError.value = exception instanceof Error ? exception.message : 'Could not update this Stash.'
  } finally {
    editSaving.value = false
  }
}

async function openDelete() {
  if (!stash.value || deleteImpactLoading.value) return
  deleteOpen.value = true
  deleteImpact.value = undefined
  actionError.value = undefined
  deleteImpactLoading.value = true

  try {
    deleteImpact.value = await fetchStashDeleteImpact(stash.value.id)
  } catch (exception) {
    actionError.value = exception instanceof Error ? exception.message : 'Could not review the deletion impact.'
  } finally {
    deleteImpactLoading.value = false
  }
}

async function confirmDelete() {
  if (!stash.value || !deleteImpact.value || deleteConfirming.value) return
  deleteConfirming.value = true
  actionError.value = undefined

  try {
    await deleteStash(stash.value.id)
    await router.push('/stashes')
  } catch (exception) {
    actionError.value = exception instanceof Error ? exception.message : 'Could not delete this Stash.'
  } finally {
    deleteConfirming.value = false
  }
}

async function retryFailed() {
  if (!stash.value || retrying.value || failedItemCount.value === 0) return
  retrying.value = true
  actionError.value = undefined
  actionNotice.value = undefined

  try {
    const commandId = await retryFailedStash(stash.value.id)
    stashOperation.value = { id: commandId, state: 'accepted' }
    actionNotice.value = 'Retry queued.'
    await loadItems()
  } catch (exception) {
    actionError.value = exception instanceof Error ? exception.message : 'Could not retry failed downloads.'
  } finally {
    retrying.value = false
  }
}

function operationFromEvent(event: LiveEvent): CommandOperation | undefined {
  if (!event.event.startsWith('job.')) return undefined
  const payload = event.payload as JobLiveEvent

  const state = event.event === 'job.created'
    ? 'accepted'
    : event.event === 'job.progress'
      ? 'running'
      : event.event === 'job.completed' ? 'completed' : 'failed'

  return {
    id: payload.commandId ?? payload.command_id ?? payload.id,
    state,
    label: payload.progressLabel ?? payload.progress_label ?? undefined,
    percent: payload.progressPercent ?? payload.progress_percent ?? null
  }
}

function scheduleRefresh() {
  if (refreshTimer) return
  refreshTimer = setTimeout(() => {
    refreshTimer = undefined
    void load()
  }, 0)
}

function handleLiveEvent(event: LiveEvent) {
  if (event.event === 'connection.restored') {
    scheduleRefresh()
    return
  }

  if (event.event === 'activity.created') {
    const mediaItemId = event.payload.mediaItemId ?? event.payload.media_item_id
    const matchesItem = mediaItemId !== undefined && items.value.items.some(item => item.media_item_id === mediaItemId)
    if ((event.payload.stashId ?? event.payload.stash_id) === stash.value?.id || matchesItem) scheduleRefresh()
    return
  }

  const operation = operationFromEvent(event)
  if (!operation) return

  const commandId = event.payload.commandId ?? event.payload.command_id
  const entityType = event.payload.entityType ?? event.payload.entity_type
  const entityId = event.payload.entityId ?? event.payload.entity_id
  const eventStashId = event.payload.stashId ?? event.payload.stash_id
  const eventMediaItemId = event.payload.mediaItemId ?? event.payload.media_item_id
  const matchesStash = entityType === 'stash' && entityId === stash.value?.id || eventStashId === stash.value?.id
  const matchesItem = eventMediaItemId !== undefined && items.value.items.some(item => item.media_item_id === eventMediaItemId)

  if (event.event.startsWith('job.')) {
    const nextJob: JobApiResource = {
      id: event.payload.id,
      command_id: commandId,
      intent: event.payload.intent ?? '',
      entity_type: entityType,
      entity_id: entityId,
      state: event.event === 'job.created' ? 'pending' : event.event === 'job.progress' ? 'processing' : event.event === 'job.completed' ? 'ready' : 'failed',
      progress_current: event.payload.progressCurrent ?? event.payload.progress_current,
      progress_total: event.payload.progressTotal ?? event.payload.progress_total,
      progress_percent: event.payload.progressPercent ?? event.payload.progress_percent,
      progress_label: event.payload.progressLabel ?? event.payload.progress_label,
      last_error: event.payload.lastError ?? event.payload.last_error,
      payload: typeof eventMediaItemId === 'string' || typeof eventStashId === 'string'
        ? {
            media_item_id: typeof eventMediaItemId === 'string' ? eventMediaItemId : null,
            stash_id: typeof eventStashId === 'string' ? eventStashId : null
          }
        : null
    }
    if (matchesStash || matchesItem) jobs.value = [nextJob, ...jobs.value.filter(job => job.id !== nextJob.id)]
  }

  if (matchesStash) {
    stashOperation.value = operation
    if (entityType !== 'media_item' || event.event === 'job.completed' || event.event === 'job.failed') scheduleRefresh()
    return
  }
  const input = inputs.value.find(candidate => candidate.id === entityId)
  const broadcast = broadcasts.value.find(candidate => candidate.id === entityId)
  const inputId = input?.id ?? Object.entries(inputOperations.value).find(([, candidate]) => candidate?.id === commandId)?.[0]
  const broadcastId = broadcast?.id ?? Object.entries(broadcastOperations.value).find(([, candidate]) => candidate?.id === commandId)?.[0]

  if (inputId) inputOperations.value = { ...inputOperations.value, [inputId]: operation }
  if (broadcastId) broadcastOperations.value = { ...broadcastOperations.value, [broadcastId]: operation }

  if ((event.event === 'job.completed' || event.event === 'job.failed') && (inputId || broadcastId || matchesItem)) scheduleRefresh()
}

async function syncInput(input: StashInputApiResource) {
  if (!stash.value || inputOperations.value[input.id] && !isTerminal(inputOperations.value[input.id])) return

  try {
    const operation = await syncStashInput(stash.value.id, input.id)
    inputOperations.value = { ...inputOperations.value, [input.id]: operation }
  } catch {
    inputOperations.value = { ...inputOperations.value, [input.id]: { id: '', state: 'failed' } }
  }
}

async function rebuild(broadcast: BroadcastApiResource) {
  if (broadcastOperations.value[broadcast.id] && !isTerminal(broadcastOperations.value[broadcast.id])) return

  try {
    const operation = await rebuildBroadcast(broadcast.id)
    broadcastOperations.value = { ...broadcastOperations.value, [broadcast.id]: operation }
  } catch {
    broadcastOperations.value = { ...broadcastOperations.value, [broadcast.id]: { id: '', state: 'failed' } }
  }
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
  if (bytes === null || bytes === undefined || bytes <= 0) {
    const estimate = item.media_item?.size_bytes
    if (estimate === null || estimate === undefined || estimate <= 0) return '—'
    if (estimate < 1024 * 1024) return `${item.media_item?.size_estimated ? '~' : ''}${Math.round(estimate / 1024)} KB`
    if (estimate < 1024 * 1024 * 1024) return `${item.media_item?.size_estimated ? '~' : ''}${(estimate / (1024 * 1024)).toFixed(1)} MB`
    return `${item.media_item?.size_estimated ? '~' : ''}${(estimate / (1024 * 1024 * 1024)).toFixed(1)} GB`
  }
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

function itemThumbnail(item: StashItemApiResource) {
  if (item.media_item?.thumbnail_uri) return h('img', { src: item.media_item.thumbnail_uri, alt: '', class: 'aspect-video w-14 shrink-0 rounded-md object-cover sm:w-16' })
  return h('div', { class: 'flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-elevated sm:w-16' }, [
    h(resolveComponent('UIcon'), { name: 'i-lucide-play', class: 'size-3.5 text-dimmed' })
  ])
}

function activeJobFor(item: StashItemApiResource) {
  return jobs.value.find(job => job.entity_type === 'media_item' && job.entity_id === item.media_item_id && (job.state === 'processing' || job.state === 'pending'))
}

function sortItems(key: string) {
  itemSort.value = itemSort.value.key === key ? { key, direction: itemSort.value.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: key === 'published' ? 'desc' : 'asc' }
}

function sortHeader(label: string, key: string) {
  const arrow = itemSort.value.key === key ? itemSort.value.direction === 'asc' ? ' ↑' : ' ↓' : ''
  return h('button', { type: 'button', class: 'transition-colors hover:text-highlighted', onClick: () => sortItems(key) }, label + arrow)
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
    id: 'title',
    header: () => sortHeader('Title', 'title'),
    cell: ({ row }) => h('div', { class: 'flex items-center gap-2.5' }, [
      itemThumbnail(row.original),
      h(resolveComponent('RouterLink'), { to: `/vault/${row.original.media_item_id}`, class: 'block whitespace-normal font-mono text-sm text-highlighted hover:text-primary' }, () => itemTitle(row.original))
    ])
  },
  {
    id: 'published',
    header: () => sortHeader('Published', 'published'),
    cell: ({ row }) => row.original.media_item?.published_at
      ? h(resolveComponent('UTooltip'), { text: absoluteTime(row.original.media_item.published_at) }, () =>
        h('time', { datetime: row.original.media_item?.published_at, class: 'whitespace-nowrap font-mono text-xs text-dimmed' }, relativeDate(row.original.media_item?.published_at)))
      : h('span', { class: 'font-mono text-xs text-dimmed' }, '—')
  },
  { id: 'duration', header: () => sortHeader('Duration', 'duration'), cell: ({ row }) => h('span', { class: 'font-mono text-xs text-muted' }, itemDuration(row.original)) },
  { id: 'size', header: () => sortHeader('Size', 'size'), cell: ({ row }) => h('span', { class: 'font-mono text-xs text-muted' }, itemSize(row.original)) },
  { id: 'status', header: () => sortHeader('Status', 'status'), cell: ({ row }) => h('div', { class: 'space-y-1' }, [itemStatusCell(row.original), ...(activeJobFor(row.original) ? [h(OperationProgress, { variant: 'compact', percent: activeJobFor(row.original)?.progress_percent ?? null, status: 'active', class: 'w-24' })] : [])]) }
]

const itemTableUi = {
  thead: 'bg-elevated/60',
  th: 'font-mono text-xs uppercase tracking-wider text-dimmed py-2 px-3',
  td: 'py-2 px-3',
  tbody: 'divide-y divide-default/60'
}

const itemSearch = ref(typeof route.query.search === 'string' ? route.query.search : '')
const itemStatusFilter = ref(typeof route.query.status === 'string' ? route.query.status : 'all')
const itemStatusFilterOptions = [
  { label: 'All statuses', value: 'all' },
  { label: 'Ready', value: 'ready' },
  { label: 'Downloading', value: 'downloading' },
  { label: 'Pending', value: 'download_pending' },
  { label: 'Failed', value: 'failed' },
  { label: 'Ignored', value: 'ignored' }
]
const itemsPageSize = 20
const itemsPage = ref(Math.max(1, Number(route.query.page) || 1))
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
  const generation = ++itemsLoadGeneration

  itemsLoading.value = true
  itemsError.value = undefined

  try {
    const nextItems = await fetchStashItems(stashId, {
      limit: itemsPageSize,
      offset: (itemsPage.value - 1) * itemsPageSize,
      search: itemSearch.value.trim() || undefined,
      status: itemStatusFilter.value === 'all' ? undefined : itemStatusFilter.value,
      sort: itemSort.value.key,
      direction: itemSort.value.direction
    })
    if (generation === itemsLoadGeneration) items.value = nextItems
  } catch (exception) {
    if (generation === itemsLoadGeneration) itemsError.value = exception instanceof Error ? exception.message : 'Could not load Stash items.'
  } finally {
    if (generation === itemsLoadGeneration) itemsLoading.value = false
  }
}

async function loadJobs() {
  try { jobs.value = await fetchJobs() } catch { jobs.value = [] }
}

async function load() {
  const stashId = String(route.params.id)
  const generation = ++loadGeneration
  const replacingPage = stash.value?.id !== stashId
  if (replacingPage) loading.value = true
  error.value = replacingPage ? undefined : error.value
  refreshError.value = undefined
  inputsError.value = undefined
  broadcastsError.value = undefined
  itemsError.value = undefined
  if (replacingPage) {
    stash.value = undefined
    inputs.value = []
    broadcasts.value = []
    items.value = { items: [], total: 0, limit: itemsPageSize, offset: 0, stash_item_count: 0 }
  }

  try {
    const nextStash = await fetchStash(stashId)
    if (generation !== loadGeneration) return
    stash.value = nextStash
  } catch (exception) {
    if (generation !== loadGeneration) return
    if (replacingPage) {
      error.value = exception instanceof Error ? exception.message : 'Could not load this Stash.'
      loading.value = false
    } else {
      refreshError.value = exception instanceof Error ? exception.message : 'Could not refresh this Stash.'
    }
    return
  }

  const [inputResult, broadcastResult] = await Promise.allSettled([
    fetchStashInputs(stashId),
    fetchStashBroadcasts(stashId)
  ])
  if (generation !== loadGeneration) return

  if (inputResult.status === 'fulfilled') inputs.value = inputResult.value
  else inputsError.value = inputResult.reason instanceof Error ? inputResult.reason.message : 'Could not load Inputs.'

  if (broadcastResult.status === 'fulfilled') broadcasts.value = broadcastResult.value
  else broadcastsError.value = broadcastResult.reason instanceof Error ? broadcastResult.reason.message : 'Could not load Broadcasts.'

  for (const input of inputs.value) {
    if (input.sync_operation && !isTerminal(input.sync_operation)) inputOperations.value = { ...inputOperations.value, [input.id]: input.sync_operation }
  }
  for (const broadcast of broadcasts.value) {
    if (broadcast.rebuild_operation && !isTerminal(broadcast.rebuild_operation)) broadcastOperations.value = { ...broadcastOperations.value, [broadcast.id]: broadcast.rebuild_operation }
  }

  if (replacingPage) loading.value = false
  await Promise.all([loadItems(), loadJobs()])
}

function syncItemQuery() {
  const query = { ...route.query }
  const search = itemSearch.value.trim()
  if (search) query.search = search
  else delete query.search
  if (itemStatusFilter.value !== 'all') query.status = itemStatusFilter.value
  else delete query.status
  if (itemsPage.value > 1) query.page = String(itemsPage.value)
  else delete query.page
  if (itemSort.value.key !== 'published') query.sort = itemSort.value.key
  else delete query.sort
  if (itemSort.value.direction !== 'desc') query.dir = itemSort.value.direction
  else delete query.dir
  void router.replace({ query })
}

watch([itemSearch, itemStatusFilter], () => { itemsPage.value = 1 })
watch([itemSearch, itemStatusFilter, itemsPage, itemSort], () => { syncItemQuery(); void loadItems() })
watch(() => [route.query.search, route.query.status, route.query.page, route.query.sort, route.query.dir], ([search, status, page, sort, dir]) => {
  itemSearch.value = typeof search === 'string' ? search : ''
  itemStatusFilter.value = typeof status === 'string' ? status : 'all'
  itemsPage.value = Math.max(1, Number(page) || 1)
  itemSort.value = { key: typeof sort === 'string' ? sort : 'published', direction: dir === 'asc' ? 'asc' : 'desc' }
})
watch(() => route.params.id, () => { void load() })
onMounted(load)
onMounted(() => { unsubscribe = subscribeLiveUpdates(handleLiveEvent) })
onBeforeUnmount(() => {
  unsubscribe?.()
  if (refreshTimer) clearTimeout(refreshTimer)
})
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
      <div class="flex min-w-0 flex-1 items-start gap-4">
        <img v-if="stash.icon_uri" :src="stash.icon_uri" alt="" class="size-14 shrink-0 rounded-md object-cover" />
        <div v-else class="flex size-14 shrink-0 items-center justify-center rounded-md bg-elevated font-mono text-lg text-muted">{{ monogram(stash.name) }}</div>
        <div class="min-w-0 flex-1">
          <h1 class="truncate font-mono text-2xl leading-tight text-highlighted">{{ stash.name }}</h1>
          <div class="mt-2 flex items-center gap-1.5">
            <span class="size-1.5 rounded-full" :class="statePresentation(stash.state).dot" />
            <span class="text-xs" :class="statePresentation(stash.state).text">{{ statePresentation(stash.state).label }}</span>
          </div>
          <p class="mt-1 text-sm text-muted">{{ items.stash_item_count.toLocaleString() }} items<span v-if="stash.updated_at"> · updated {{ relativeDate(stash.updated_at) }}</span></p>
          <p v-if="stash.description" class="mt-2 max-w-md text-sm text-muted">{{ stash.description }}</p>
        </div>
      </div>
      <UDropdownMenu
          :items="[
            { label: 'Edit Stash', icon: 'i-lucide-pencil', onSelect: openEdit },
            ...(failedItemCount > 0 ? [{ label: `Retry failed downloads (${failedItemCount})`, icon: 'i-lucide-refresh-cw', onSelect: retryFailed, disabled: retrying }] : []),
            { type: 'separator' },
            { label: 'Delete Stash', icon: 'i-lucide-trash-2', color: 'error', onSelect: openDelete }
          ]"
          :content="{ align: 'end' }"
      >
        <UButton icon="i-lucide-ellipsis" aria-label="Stash actions" title="Stash actions" variant="ghost" color="neutral" />
      </UDropdownMenu>
    </header>

    <UAlert v-if="actionError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Stash action failed" :description="actionError" />
    <UAlert v-if="refreshError" color="warning" variant="subtle" icon="i-lucide-refresh-cw" title="Live update unavailable" :description="refreshError" />
    <UAlert v-if="actionNotice" color="success" variant="subtle" icon="i-lucide-check" :description="actionNotice" />
    <p v-if="operationText(stashOperation, 'Retry')" class="text-xs text-dimmed">{{ operationText(stashOperation, 'Retry') }}</p>

    <USeparator />

    <section class="space-y-3">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-baseline gap-2">
          <h2 class="text-base font-medium text-highlighted">Inputs</h2>
          <span class="font-mono text-xs text-dimmed">{{ inputs.length }}</span>
        </div>
        <UButton label="New input" icon="i-lucide-plus" variant="ghost" color="neutral" size="sm" @click="router.push(`/stashes/${stash.id}/inputs/new`)" />
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
          <div class="flex shrink-0 flex-col items-end gap-1.5 sm:flex-row sm:items-center">
            <p v-if="operationText(inputOperations[input.id], 'Sync')" class="text-xs" :class="inputOperations[input.id]?.state === 'failed' ? 'text-error' : 'text-dimmed'">
              {{ operationText(inputOperations[input.id], 'Sync') }}
            </p>
            <UButton :label="inputOperations[input.id] && !isTerminal(inputOperations[input.id]) ? 'Syncing…' : 'Sync now'" icon="i-lucide-refresh-cw" :loading="inputOperations[input.id]?.state === 'running'" :disabled="Boolean(inputOperations[input.id] && !isTerminal(inputOperations[input.id]))" variant="ghost" color="neutral" size="sm" @click="syncInput(input)" />
            <UButton label="Configure" icon="i-lucide-settings" variant="ghost" color="neutral" size="sm" @click="router.push(`/stashes/${stash.id}/inputs/${input.id}/configure`)" />
          </div>
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
        <UButton label="New broadcast" icon="i-lucide-plus" variant="ghost" color="neutral" size="sm" @click="router.push(`/stashes/${stash.id}/broadcasts/new`)" />
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
            <div class="flex shrink-0 flex-col items-end gap-1.5 sm:flex-row sm:items-center">
              <p v-if="operationText(broadcastOperations[broadcast.id], 'Rebuild')" class="text-xs" :class="broadcastOperations[broadcast.id]?.state === 'failed' ? 'text-error' : 'text-dimmed'">
                {{ operationText(broadcastOperations[broadcast.id], 'Rebuild') }}
              </p>
              <UButton :label="broadcastOperations[broadcast.id] && !isTerminal(broadcastOperations[broadcast.id]) ? 'Rebuilding…' : 'Rebuild'" icon="i-lucide-refresh-cw" :loading="broadcastOperations[broadcast.id]?.state === 'running'" :disabled="Boolean(broadcastOperations[broadcast.id] && !isTerminal(broadcastOperations[broadcast.id]))" variant="ghost" color="neutral" size="sm" @click="rebuild(broadcast)" />
              <UButton label="Details" icon="i-lucide-arrow-up-right" :to="`/broadcasts/${broadcast.id}`" variant="subtle" color="neutral" size="sm" />
            </div>
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
        <UButton label="New broadcast" variant="ghost" color="neutral" size="sm" class="mt-2" @click="router.push(`/stashes/${stash.id}/broadcasts/new`)" />
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
        <UButton v-if="failedItemCount" :label="`Retry failed (${failedItemCount})`" icon="i-lucide-refresh-cw" variant="soft" color="error" size="sm" :loading="retrying" @click="retryFailed" />
      </div>

      <UAlert v-if="itemsError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load Items" :description="itemsError" />
      <div v-else-if="itemsLoading && !items.items.length" class="flex items-center gap-2 rounded-md bg-muted p-4 text-sm text-muted">
        <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
        Loading items…
      </div>
      <template v-else-if="items.items.length">
        <div v-if="downloadOperation" class="w-full">
          <OperationProgress
            label="Downloading"
            :percent="downloadOperation.percent"
            :stage="downloadOperation.stage"
            :count="downloadOperation.count"
            status="active"
          />
        </div>
        <div class="hidden overflow-hidden rounded-md border border-default bg-muted md:block">
        <UTable :data="items.items" :columns="itemColumns" :ui="itemTableUi" class="text-sm" />
        </div>

        <div class="space-y-2 md:hidden">
          <RouterLink v-for="item in items.items" :key="item.id" :to="`/vault/${item.media_item_id}`" class="flex items-center gap-3 rounded-md bg-muted p-3 hover:bg-elevated">
            <img v-if="item.media_item?.thumbnail_uri" :src="item.media_item.thumbnail_uri" alt="" class="aspect-video w-14 shrink-0 rounded-md object-cover" />
            <div v-else class="flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-elevated"><UIcon name="i-lucide-play" class="size-3.5 text-dimmed" /></div>
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
          </RouterLink>
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

    <UModal v-model:open="editOpen" title="Edit Stash" :ui="{ content: 'max-w-lg' }">
      <template #body>
        <UForm class="space-y-4" @submit="saveEdit">
          <UAlert v-if="editError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not update Stash" :description="editError" />
          <UFormField label="Name" required><UInput v-model="editForm.name" required /></UFormField>
          <UFormField label="Description"><UTextarea v-model="editForm.description" :rows="3" /></UFormField>
          <UFormField label="Sync mode"><USelect v-model="editForm.sync_mode" :items="syncModeOptions" value-key="value" /></UFormField>
          <UFormField label="Download policy"><USelect v-model="editForm.download_policy" :items="downloadPolicyOptions" value-key="value" /></UFormField>
          <UFormField label="Organisation mode"><USelect v-model="editForm.organization_mode" :items="organizationModeOptions" value-key="value" /></UFormField>
          <div class="flex justify-end gap-2">
            <UButton label="Cancel" variant="ghost" color="neutral" :disabled="editSaving" @click="editOpen = false" />
            <UButton type="submit" label="Save changes" :loading="editSaving" :disabled="!editForm.name.trim()" />
          </div>
        </UForm>
      </template>
    </UModal>

    <UModal v-model:open="deleteOpen" title="Delete Stash" :ui="{ content: 'max-w-lg' }">
      <template #body>
        <div v-if="deleteImpactLoading" class="flex items-center gap-2 text-sm text-muted">
          <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
          Reviewing what will be affected…
        </div>
        <div v-else-if="deleteImpact" class="space-y-4">
          <p class="text-sm text-muted">This removes the Stash, its Inputs, and its Broadcasts. Preserved Vault items are retained.</p>
          <div class="space-y-2 text-sm">
            <p v-if="deleteImpact.orphaned_items.length"><span class="font-mono text-highlighted">{{ deleteImpact.orphaned_items.length }}</span> Vault item{{ deleteImpact.orphaned_items.length === 1 ? '' : 's' }} will no longer belong to a Stash.</p>
            <p v-if="deleteImpact.shared_items.length"><span class="font-mono text-highlighted">{{ deleteImpact.shared_items.length }}</span> Vault item{{ deleteImpact.shared_items.length === 1 ? '' : 's' }} are shared with another Stash and will remain linked there.</p>
            <p v-if="!deleteImpact.orphaned_items.length && !deleteImpact.shared_items.length" class="text-muted">No Vault item relationships will be affected.</p>
          </div>
          <UAlert v-if="actionError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not delete Stash" :description="actionError" />
          <div class="flex justify-end gap-2">
            <UButton label="Cancel" variant="ghost" color="neutral" :disabled="deleteConfirming" @click="deleteOpen = false" />
            <UButton label="Delete Stash" color="error" :loading="deleteConfirming" @click="confirmDelete" />
          </div>
        </div>
        <UAlert v-else color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not review deletion" :description="actionError ?? 'Try again.'" />
      </template>
    </UModal>
  </main>
</template>
