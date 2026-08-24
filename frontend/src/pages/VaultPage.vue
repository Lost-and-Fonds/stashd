<script setup lang="ts">
import { computed, h, onBeforeUnmount, onMounted, ref, resolveComponent, watch } from 'vue'
import { useRouter } from 'vue-router'
import type { TableColumn, TableRow } from '@nuxt/ui'

import { fetchVaultItems, type VaultItemApiResource } from '../api/vault'
import { subscribeLiveUpdates, type LiveEvent } from '../live/mercure'

const router = useRouter()
const items = ref<VaultItemApiResource[]>([])
const total = ref(0)
const vaultTotal = ref(0)
const preservedSizeBytes = ref<number | null>(null)
const loading = ref(true)
const error = ref<string>()
const search = ref('')
const kind = ref('all')
const page = ref(1)
const pageSize = 50

const kindMeta: Record<string, { label: string, icon: string }> = {
  video: { label: 'Video', icon: 'i-lucide-video' },
  audio: { label: 'Audio', icon: 'i-lucide-headphones' },
  image: { label: 'Image', icon: 'i-lucide-image' },
  subtitle: { label: 'Subtitle', icon: 'i-lucide-captions' },
  metadata: { label: 'Metadata', icon: 'i-lucide-file-json' },
  other: { label: 'Other', icon: 'i-lucide-file' }
}

const kindOptions = [
  { label: 'All types', value: 'all' },
  ...Object.entries(kindMeta).map(([value, meta]) => ({ label: meta.label, value }))
]
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / pageSize)))
const resultSummary = computed(() => {
  const size = preservedSizeBytes.value === null ? '' : ` · ${formatBytes(preservedSizeBytes.value)} total preserved`
  return `${total.value.toLocaleString()} of ${vaultTotal.value.toLocaleString()} items${size}`
})

function formatBytes(bytes: number) {
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

function absoluteTime(value: string) {
  return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

function addedDate(value: string) {
  return new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

function itemKind(item: VaultItemApiResource) {
  return kindMeta[item.kind ?? 'other'] ?? kindMeta.other
}

function openItem(item: VaultItemApiResource) {
  router.push(`/vault/${item.id}`)
}

async function load() {
  loading.value = true
  error.value = undefined

  try {
    const response = await fetchVaultItems({
      limit: pageSize,
      offset: (page.value - 1) * pageSize,
      search: search.value.trim() || undefined,
      kind: kind.value === 'all' ? undefined : kind.value
    })
    items.value = response.items
    total.value = response.total
    vaultTotal.value = response.vaultTotal
    preservedSizeBytes.value = response.preservedSizeBytes
  } catch (exception) {
    items.value = []
    error.value = exception instanceof Error ? exception.message : 'Could not load the Vault.'
  } finally {
    loading.value = false
  }
}

function titleCell(item: VaultItemApiResource) {
  const meta = itemKind(item)
  return h('div', { class: 'flex items-center gap-2.5' }, [
    h('div', { class: 'flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-elevated sm:w-16' }, [
      h(resolveComponent('UIcon'), { name: meta.icon, class: 'size-4 text-dimmed' })
    ]),
    h('div', { class: 'min-w-0' }, [
      h('p', { class: 'truncate font-mono text-sm text-highlighted' }, item.title),
      h('p', { class: 'truncate text-xs text-dimmed' }, item.providerKey)
    ])
  ])
}

function typeCell(item: VaultItemApiResource) {
  const meta = itemKind(item)
  return h('span', { class: 'inline-flex items-center gap-1.5 text-xs text-toned' }, [
    h(resolveComponent('UIcon'), { name: meta.icon, class: 'size-3.5 shrink-0 text-dimmed' }), meta.label
  ])
}

function contextCell(item: VaultItemApiResource) {
  const chips = []
  if (item.stashCount > 0) chips.push(h(resolveComponent('UTooltip'), { text: `In ${item.stashCount} stash${item.stashCount === 1 ? '' : 'es'}` }, () => h('span', { class: 'inline-flex items-center gap-1 font-mono text-xs text-dimmed' }, [h(resolveComponent('UIcon'), { name: 'i-lucide-inbox', class: 'size-3.5' }), item.stashCount])))
  if (item.broadcastCount > 0) chips.push(h(resolveComponent('UTooltip'), { text: `Included by ${item.broadcastCount} broadcast${item.broadcastCount === 1 ? '' : 's'}` }, () => h('span', { class: 'inline-flex items-center gap-1 font-mono text-xs text-dimmed' }, [h(resolveComponent('UIcon'), { name: 'i-lucide-radio', class: 'size-3.5' }), item.broadcastCount])))
  return chips.length > 0 ? h('div', { class: 'flex items-center gap-3' }, chips) : null
}

const columns: TableColumn<VaultItemApiResource>[] = [
  { accessorKey: 'title', header: 'Item', cell: ({ row }) => titleCell(row.original) },
  { accessorKey: 'kind', header: 'Type', cell: ({ row }) => typeCell(row.original) },
  { accessorKey: 'createdAt', header: 'Added', cell: ({ row }) => h(resolveComponent('UTooltip'), { text: absoluteTime(row.original.createdAt) }, () => h('time', { datetime: row.original.createdAt, class: 'whitespace-nowrap font-mono text-xs text-dimmed' }, addedDate(row.original.createdAt))) },
  { accessorKey: 'preservedSizeBytes', header: 'Size', cell: ({ row }) => h('span', { class: 'font-mono text-xs text-muted' }, formatBytes(row.original.preservedSizeBytes)) },
  { id: 'context', header: '', cell: ({ row }) => contextCell(row.original) }
]

const tableUi = { thead: 'bg-elevated/60', th: 'font-mono text-xs uppercase tracking-wider text-dimmed py-2 px-3', td: 'py-2 px-3', tbody: 'divide-y divide-default/60 [&_tr]:cursor-pointer [&_tr]:transition-colors [&_tr]:hover:bg-elevated/60' }

function onSelectRow(_event: Event, row: TableRow<VaultItemApiResource>) { openItem(row.original) }

let refreshTimer: ReturnType<typeof setTimeout> | undefined
let unsubscribe: (() => void) | undefined

function scheduleLiveRefresh() {
  if (refreshTimer) return
  refreshTimer = setTimeout(() => {
    refreshTimer = undefined
    void load()
  }, 50)
}

function handleLiveEvent(event: LiveEvent) {
  if (event.event === 'activity.created') {
    if (['download.completed', 'download.failed', 'stash.input_added', 'stash.input_synced', 'stash.retried_failed', 'vault.verify_completed'].includes(event.payload.type ?? '')) scheduleLiveRefresh()
    return
  }

  if ((event.event === 'job.completed' || event.event === 'job.failed') && (event.payload.intent?.startsWith('download') || event.payload.entityType === 'media_item')) scheduleLiveRefresh()
}

watch([search, kind], () => { page.value = 1; void load() })
watch(page, () => { void load() })
onMounted(() => {
  void load()
  unsubscribe = subscribeLiveUpdates(handleLiveEvent)
})
onBeforeUnmount(() => {
  unsubscribe?.()
  if (refreshTimer) clearTimeout(refreshTimer)
})
</script>

<template>
  <main class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-8">
    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">Vault</h1>
      <p class="text-sm text-muted">Everything Stashd has preserved.</p>
      <p v-if="!loading && !error" class="font-mono text-xs text-dimmed">{{ resultSummary }}</p>
    </header>

    <div class="flex flex-col gap-2 sm:flex-row">
      <UInput v-model="search" placeholder="Search the vault" icon="i-lucide-search" size="lg" class="flex-1" />
      <USelect v-model="kind" :items="kindOptions" value-key="value" class="sm:w-40" />
    </div>

    <div v-if="loading" class="flex items-center gap-2 rounded-md bg-muted p-4 text-sm text-muted"><UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />Loading Vault…</div>
    <UAlert v-else-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load Vault" :description="error" />
    <template v-else-if="items.length">
      <div class="hidden overflow-hidden rounded-md border border-default bg-muted md:block"><UTable :data="items" :columns="columns" :ui="tableUi" class="text-sm" @select="onSelectRow" /></div>
      <div class="space-y-2 md:hidden">
        <RouterLink v-for="item in items" :key="item.id" :to="`/vault/${item.id}`" class="flex items-center gap-3 rounded-md bg-muted p-3 transition-colors hover:bg-elevated/40">
          <div class="flex aspect-video w-14 shrink-0 items-center justify-center rounded-md bg-elevated"><UIcon :name="itemKind(item).icon" class="size-4 text-dimmed" /></div>
          <div class="min-w-0 flex-1 space-y-1"><p class="truncate font-mono text-sm text-highlighted">{{ item.title }}</p><p class="truncate text-xs text-dimmed">{{ item.providerKey }}</p><div class="flex flex-wrap gap-x-3 gap-y-1 font-mono text-xs text-dimmed"><span>{{ addedDate(item.createdAt) }}</span><span>{{ formatBytes(item.preservedSizeBytes) }}</span><span v-if="item.stashCount" class="inline-flex items-center gap-1"><UIcon name="i-lucide-inbox" class="size-3.5" />{{ item.stashCount }}</span><span v-if="item.broadcastCount" class="inline-flex items-center gap-1"><UIcon name="i-lucide-radio" class="size-3.5" />{{ item.broadcastCount }}</span></div></div>
        </RouterLink>
      </div>
      <div v-if="totalPages > 1" class="flex items-center justify-between"><p class="font-mono text-xs text-dimmed">Page {{ page }} of {{ totalPages }}</p><UPagination v-model:page="page" :total="total" :items-per-page="pageSize" /></div>
    </template>
    <div v-else class="rounded-md bg-muted p-4 text-center"><p class="text-sm text-muted">No items match these filters.</p><UButton v-if="search || kind !== 'all'" label="Clear filters" variant="ghost" color="neutral" size="sm" class="mt-2" @click="search = ''; kind = 'all'" /></div>
  </main>
</template>
