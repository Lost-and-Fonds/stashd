<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { deleteStash, fetchStashDeleteImpact, fetchStashes, syncStash } from '../api/stashes'
import { formatRelativeDate } from '../utils/formatDate'
import { subscribeLiveUpdates, type LiveEvent } from '../live/mercure'
import type { StashApiResource, StashDeleteImpact } from '../types/stash'

const stashes = ref<StashApiResource[]>([])
const router = useRouter()
const loading = ref(true)
const error = ref<string>()
const syncing = ref(false)
const syncingStashId = ref<string>()
const syncNotice = ref<string>()
const syncError = ref<string>()
const deleteOpen = ref(false)
const deleteStashTarget = ref<StashApiResource>()
const deleteImpact = ref<StashDeleteImpact>()
const deleteLoading = ref(false)
const deleteConfirming = ref(false)
const deleteError = ref<string>()
let unsubscribe: (() => void) | undefined
let refreshTimer: ReturnType<typeof setTimeout> | undefined

function monogram(name: string) {
  return name.charAt(0).toUpperCase()
}

function formatBytes(bytes?: number | null) {
  if (bytes === null || bytes === undefined) return '—'
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

function statePresentation(state: string) {
  const presentation = {
    ready: { dot: 'bg-success', text: 'text-success' },
    failed: { dot: 'bg-error', text: 'text-error' },
    disabled: { dot: 'bg-neutral-400', text: 'text-dimmed' }
  }[state] ?? { dot: 'bg-neutral-400', text: 'text-dimmed' }

  return {
    label: state.replaceAll('_', ' '),
    ...presentation
  }
}

async function load(showLoading = true) {
  if (showLoading) loading.value = true
  error.value = undefined

  try {
    stashes.value = await fetchStashes()
  } catch (exception) {
    if (showLoading) stashes.value = []
    error.value = exception instanceof Error ? exception.message : 'Could not load Stashes.'
  } finally {
    loading.value = false
  }
}

async function syncAll() {
  syncing.value = true
  syncNotice.value = undefined
  syncError.value = undefined

  const results = await Promise.allSettled(stashes.value.map(stash => syncStash(stash.id)))
  const failed = results.filter(result => result.status === 'rejected').length

  if (failed === 0) {
    syncNotice.value = `Sync queued for ${stashes.value.length} stash${stashes.value.length === 1 ? '' : 'es'}.`
  } else {
    syncError.value = `${failed} stash${failed === 1 ? '' : 'es'} could not be queued for sync.`
  }

  syncing.value = false
}

async function syncOne(stash: StashApiResource) {
  if (syncingStashId.value) return
  syncingStashId.value = stash.id
  syncError.value = undefined

  try {
    await syncStash(stash.id)
    syncNotice.value = `Sync queued for ${stash.name}.`
  } catch (exception) {
    syncError.value = exception instanceof Error ? exception.message : `Could not queue ${stash.name} for sync.`
  } finally {
    syncingStashId.value = undefined
  }
}

function editStash(stash: StashApiResource) {
  void router.push({ name: 'stash-detail', params: { id: stash.id }, query: { edit: '1' } })
}

async function openDelete(stash: StashApiResource) {
  deleteStashTarget.value = stash
  deleteImpact.value = undefined
  deleteError.value = undefined
  deleteOpen.value = true
  deleteLoading.value = true

  try {
    deleteImpact.value = await fetchStashDeleteImpact(stash.id)
  } catch (exception) {
    deleteError.value = exception instanceof Error ? exception.message : 'Could not review the deletion impact.'
  } finally {
    deleteLoading.value = false
  }
}

async function confirmDelete() {
  if (!deleteStashTarget.value || !deleteImpact.value || deleteConfirming.value) return
  deleteConfirming.value = true
  deleteError.value = undefined

  try {
    await deleteStash(deleteStashTarget.value.id)
    stashes.value = stashes.value.filter(stash => stash.id !== deleteStashTarget.value?.id)
    deleteOpen.value = false
  } catch (exception) {
    deleteError.value = exception instanceof Error ? exception.message : 'Could not delete this Stash.'
  } finally {
    deleteConfirming.value = false
  }
}

function refreshFromLiveEvent(event: LiveEvent) {
  const stashChanged = event.event === 'activity.created' && ['stash.created', 'stash.updated', 'stash.input_added', 'stash.input_updated'].includes(event.payload.type ?? '')
  if (!stashChanged && event.event !== 'job.completed' && event.event !== 'job.failed') return
  if (refreshTimer) return
  refreshTimer = setTimeout(() => {
    refreshTimer = undefined
    void load(false)
  }, 250)
}

onMounted(() => {
  unsubscribe = subscribeLiveUpdates(refreshFromLiveEvent)
  void load()
})

onBeforeUnmount(() => {
  unsubscribe?.()
  if (refreshTimer) clearTimeout(refreshTimer)
})
</script>

<template>
  <main class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-8">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-highlighted">Stashes</h1>
        <p class="mt-1 text-sm text-muted">Everything you're preserving, at a glance.</p>
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <UButton label="Sync all" icon="i-lucide-refresh-cw" variant="ghost" color="neutral" size="sm" :loading="syncing" :disabled="syncing || stashes.length === 0" @click="syncAll" />
        <RouterLink to="/stashes/new" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-2 font-mono text-sm font-medium text-inverted transition-colors hover:bg-primary/90">
          <UIcon name="i-lucide-plus" class="size-4" />
          New stash
        </RouterLink>
      </div>
    </header>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
      Loading Stashes…
    </div>
    <UAlert v-else-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load Stashes" :description="error" />
    <UAlert v-if="syncNotice" color="success" variant="subtle" icon="i-lucide-check" :description="syncNotice" />
    <UAlert v-if="syncError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Sync failed" :description="syncError" />
    <div v-else-if="stashes.length === 0" class="rounded-md border border-dashed border-default p-8 text-center">
      <p class="text-sm text-muted">No Stashes yet.</p>
      <UButton label="Create your first Stash" to="/stashes/new" size="sm" class="mt-3" />
    </div>
    <div v-else class="divide-y divide-default rounded-md border border-default">
      <div v-for="stash in stashes" :key="stash.id" class="flex items-start gap-3 p-4 transition-colors hover:bg-elevated/40 sm:p-5">
        <RouterLink :to="{ name: 'stash-detail', params: { id: stash.id } }" class="flex min-w-0 flex-1 items-start gap-3">
          <img v-if="stash.icon_uri" :src="stash.icon_uri" alt="" class="size-11 shrink-0 rounded-md object-cover" />
          <div v-else class="flex size-11 shrink-0 items-center justify-center rounded-md bg-elevated font-mono text-sm text-muted">{{ monogram(stash.name) }}</div>
          <div class="min-w-0 flex-1">
            <p class="truncate font-mono text-base leading-tight text-highlighted">{{ stash.name }}</p>
            <p class="mt-1 flex items-center gap-1.5 text-xs" :class="statePresentation(stash.state).text">
              <span class="size-1.5 rounded-full" :class="statePresentation(stash.state).dot" />
              {{ statePresentation(stash.state).label }}
              <span v-if="stash.last_discovery_at" class="text-dimmed">· updated {{ formatRelativeDate(stash.last_discovery_at) }}</span>
            </p>
            <p v-if="stash.item_count !== undefined" class="mt-1 text-xs text-dimmed">{{ stash.item_count.toLocaleString() }} items · {{ formatBytes(stash.storage_bytes) }}<span v-if="stash.input_summary?.length"> · {{ stash.input_summary.length }} input{{ stash.input_summary.length === 1 ? '' : 's' }} · {{ stash.input_summary.join(', ') }}</span></p>
            <p v-if="stash.description" class="mt-2 truncate text-sm text-muted">{{ stash.description }}</p>
          </div>
        </RouterLink>
        <UDropdownMenu
          :items="[
            { label: 'Edit', icon: 'i-lucide-pencil', onSelect: () => editStash(stash) },
            { label: syncingStashId === stash.id ? 'Syncing…' : 'Sync', icon: 'i-lucide-refresh-cw', disabled: Boolean(syncingStashId), onSelect: () => syncOne(stash) },
            { type: 'separator' },
            { label: 'Delete', icon: 'i-lucide-trash-2', color: 'error', onSelect: () => openDelete(stash) }
          ]"
          :content="{ align: 'end' }"
        >
          <UButton icon="i-lucide-ellipsis" aria-label="Stash actions" title="Stash actions" variant="ghost" color="neutral" size="sm" />
        </UDropdownMenu>
      </div>
    </div>

    <UModal v-model:open="deleteOpen" title="Delete Stash" :description="deleteStashTarget ? `Remove “${deleteStashTarget.name}”? Preserved Vault items are retained.` : undefined" :ui="{ content: 'max-w-lg' }">
      <template #body>
        <div v-if="deleteLoading" class="flex items-center gap-2 text-sm text-muted">
          <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
          Checking deletion impact…
        </div>
        <UAlert v-else-if="deleteError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not review deletion" :description="deleteError" />
        <p v-else-if="deleteImpact" class="text-sm text-muted">This removes {{ deleteImpact.orphaned_items.length }} orphaned item{{ deleteImpact.orphaned_items.length === 1 ? '' : 's' }} and leaves shared items available to other Stashes.</p>
      </template>
      <template #footer>
        <UButton label="Cancel" variant="ghost" color="neutral" :disabled="deleteConfirming" @click="deleteOpen = false" />
        <UButton label="Delete Stash" color="error" :loading="deleteConfirming" :disabled="!deleteImpact" @click="confirmDelete" />
      </template>
    </UModal>
  </main>
</template>
