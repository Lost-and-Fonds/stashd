<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { fetchStashes } from '../api/stashes'
import { formatRelativeDate } from '../utils/formatDate'
import { subscribeLiveUpdates, type LiveEvent } from '../live/mercure'
import type { StashApiResource } from '../types/stash'

const stashes = ref<StashApiResource[]>([])
const loading = ref(true)
const error = ref<string>()
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

async function load() {
  loading.value = true
  error.value = undefined

  try {
    stashes.value = await fetchStashes()
  } catch (exception) {
    stashes.value = []
    error.value = exception instanceof Error ? exception.message : 'Could not load Stashes.'
  } finally {
    loading.value = false
  }
}

function refreshFromLiveEvent(event: LiveEvent) {
  if (event.event === 'activity.created' || event.event === 'job.completed' || event.event === 'job.failed') {
    if (refreshTimer) return
    refreshTimer = setTimeout(() => {
      refreshTimer = undefined
      void load()
    }, 0)
  }
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
      <RouterLink to="/stashes/new" class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-primary px-3 py-2 font-mono text-sm font-medium text-inverted transition-colors hover:bg-primary/90">
        <UIcon name="i-lucide-plus" class="size-4" />
        New stash
      </RouterLink>
    </header>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
      Loading Stashes…
    </div>
    <UAlert v-else-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load Stashes" :description="error" />
    <div v-else-if="stashes.length === 0" class="rounded-md border border-dashed border-default p-8 text-center">
      <p class="text-sm text-muted">No Stashes yet.</p>
      <UButton label="Create your first Stash" to="/stashes/new" size="sm" class="mt-3" />
    </div>
    <div v-else class="divide-y divide-default rounded-md border border-default">
      <div v-for="stash in stashes" :key="stash.id" class="p-4 transition-colors hover:bg-elevated/40 sm:p-5">
        <RouterLink :to="{ name: 'stash-detail', params: { id: stash.id } }" class="flex items-start gap-3">
          <img v-if="stash.icon_uri" :src="stash.icon_uri" alt="" class="size-11 shrink-0 rounded-md object-cover" />
          <div v-else class="flex size-11 shrink-0 items-center justify-center rounded-md bg-elevated font-mono text-sm text-muted">{{ monogram(stash.name) }}</div>
          <div class="min-w-0 flex-1">
            <p class="truncate font-mono text-base leading-tight text-highlighted">{{ stash.name }}</p>
            <p class="mt-1 flex items-center gap-1.5 text-xs" :class="statePresentation(stash.state).text">
              <span class="size-1.5 rounded-full" :class="statePresentation(stash.state).dot" />
              {{ statePresentation(stash.state).label }}
              <span v-if="stash.updated_at" class="text-dimmed">· updated {{ formatRelativeDate(stash.updated_at) }}</span>
            </p>
            <p v-if="stash.item_count !== undefined" class="mt-1 text-xs text-dimmed">{{ stash.item_count.toLocaleString() }} items · {{ formatBytes(stash.storage_bytes) }}<span v-if="stash.input_summary?.length"> · {{ stash.input_summary.length }} input{{ stash.input_summary.length === 1 ? '' : 's' }} · {{ stash.input_summary.join(', ') }}</span></p>
            <p v-if="stash.description" class="mt-2 truncate text-sm text-muted">{{ stash.description }}</p>
          </div>
        </RouterLink>
      </div>
    </div>
  </main>
</template>
