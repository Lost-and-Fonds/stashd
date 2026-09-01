<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { fetchVaultItem, type VaultItemDetailResponse } from '../api/vault'
import { subscribeLiveUpdates, type LiveEvent } from '../live/mercure'

const route = useRoute()
const router = useRouter()
const detail = ref<VaultItemDetailResponse>()
const loading = ref(true)
const refreshing = ref(false)
const error = ref<string>()
let refreshTimer: ReturnType<typeof setTimeout> | undefined
let unsubscribe: (() => void) | undefined

const roleLabels: Record<string, string> = {
  vault_original: 'Original',
  source_thumbnail: 'Source thumbnail',
  subtitle: 'Subtitle',
  transcript: 'Transcript',
  metadata_json: 'Metadata',
  source_json: 'Source metadata'
}

const itemKind = computed(() => {
  const assets = detail.value?.assets ?? []
  return assets.find(asset => asset.role === 'vault_original')?.kind ?? assets[0]?.kind ?? null
})
const playableAsset = computed(() => detail.value?.assets.find(asset => asset.role === 'vault_original' && ['video', 'audio', 'image'].includes(asset.kind)))
const playbackUrl = computed(() => playableAsset.value && detail.value ? `/api/v1/items/${encodeURIComponent(detail.value.item.id)}/playback` : undefined)

function formatBytes(bytes: number | null) {
  if (bytes === null) return '—'
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

function formatDate(value: string | null | undefined) {
  return value ? new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'
}

function roleLabel(role: string) {
  return roleLabels[role] ?? role
}

function goBack() {
  if (typeof router.options.history.state.back === 'string') router.back()
  else void router.push('/vault')
}

async function load() {
  const hadDetail = detail.value !== undefined

  if (detail.value === undefined) loading.value = true
  else refreshing.value = true
  error.value = undefined

  try {
    detail.value = await fetchVaultItem(String(route.params.itemId))
  } catch (exception) {
    if (!hadDetail) detail.value = undefined
    error.value = exception instanceof Error ? exception.message : 'Could not load this Vault item.'
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

function scheduleLiveRefresh() {
  if (refreshTimer) return
  refreshTimer = setTimeout(() => {
    refreshTimer = undefined
    void load()
  }, 50)
}

function handleLiveEvent(event: LiveEvent) {
  const itemId = String(route.params.itemId)

  if (event.event === 'activity.created') {
    const type = event.payload.type ?? ''
    const matchesItem = event.payload.entityType === 'media_item' && event.payload.entityId === itemId
    if (matchesItem && ['download.completed', 'download.failed'].includes(type)) scheduleLiveRefresh()
    if (type === 'vault.verify_completed') scheduleLiveRefresh()
    return
  }

  if ((event.event === 'job.completed' || event.event === 'job.failed')
    && event.payload.entityType === 'media_item'
    && event.payload.entityId === itemId) scheduleLiveRefresh()
}

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
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <button type="button" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted" @click="goBack">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Back
    </button>

    <div v-if="loading" class="flex items-center gap-2 rounded-md bg-muted p-4 text-sm text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
      Loading item…
    </div>
    <UAlert v-else-if="error && !detail" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load item" :description="error" />
    <template v-else-if="detail">
      <header class="space-y-2">
        <div class="flex items-start justify-between gap-4">
          <h1 class="font-mono text-2xl leading-tight text-highlighted">{{ detail.item.title || 'Untitled item' }}</h1>
          <UButton label="Refetch" icon="i-lucide-refresh-cw" variant="soft" color="neutral" size="sm" :loading="refreshing" @click="load" />
        </div>
        <p class="text-sm text-muted">
          {{ itemKind ?? 'Unknown kind' }} · {{ detail.item.provider_key }} · {{ formatBytes(detail.preserved_size_bytes) }} preserved
        </p>
      </header>

      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not refetch item" :description="error" />

      <section v-if="playableAsset && playbackUrl" class="space-y-3">
        <h2 class="text-base font-medium text-highlighted">Preview</h2>
        <video v-if="playableAsset.kind === 'video'" :src="playbackUrl" controls playsinline class="max-h-[70vh] w-full rounded-md bg-black" />
        <audio v-else-if="playableAsset.kind === 'audio'" :src="playbackUrl" controls class="w-full" />
        <img v-else :src="playbackUrl" :alt="detail.item.title" class="max-h-[70vh] w-full rounded-md object-contain" />
      </section>

      <section v-if="detail.plugin_metadata && Object.keys(detail.plugin_metadata).length" class="space-y-3">
        <h2 class="text-base font-medium text-highlighted">Plugin metadata</h2>
        <details open class="rounded-md bg-muted p-3">
          <summary class="cursor-pointer text-sm text-toned">Show metadata</summary>
          <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-words font-mono text-xs text-muted">{{ JSON.stringify(detail.plugin_metadata, null, 2) }}</pre>
        </details>
      </section>

      <section class="space-y-3">
        <div class="flex items-baseline gap-2">
          <h2 class="text-base font-medium text-highlighted">Preserved assets</h2>
          <span class="font-mono text-xs text-dimmed">{{ detail.assets.length }}</span>
        </div>
        <div v-if="detail.assets.length" class="space-y-2">
          <div v-for="asset in detail.assets" :key="asset.id" class="flex items-center gap-3 rounded-md bg-muted p-3">
            <div class="min-w-0 flex-1">
              <p class="text-sm text-highlighted">{{ roleLabel(asset.role) }}</p>
              <p v-if="asset.display_path" class="truncate font-mono text-xs text-dimmed">{{ asset.display_path }}</p>
              <p class="mt-0.5 text-xs text-dimmed">
                {{ asset.kind }} · {{ formatBytes(asset.size_bytes) }}<template v-if="asset.language"> · {{ asset.language }}</template>
              </p>
            </div>
            <time class="whitespace-nowrap font-mono text-xs text-dimmed">{{ formatDate(asset.created_at) }}</time>
          </div>
        </div>
        <p v-else class="rounded-md bg-muted p-4 text-sm text-muted">No preserved assets are currently recorded for this item.</p>
      </section>

      <section class="space-y-2">
        <h2 class="text-sm font-medium text-toned">Source</h2>
        <div class="space-y-1.5">
          <div><p class="text-xs text-dimmed">Provider</p><p class="font-mono text-sm text-toned">{{ detail.item.provider_key }}</p></div>
          <div><p class="text-xs text-dimmed">Provider item</p><p class="font-mono text-sm text-toned">{{ detail.item.provider_item_id }}</p></div>
          <div><p class="text-xs text-dimmed">Canonical source</p><p class="break-all font-mono text-sm text-toned">{{ detail.item.canonical_uri }}</p></div>
          <div v-if="detail.item.content_type"><p class="text-xs text-dimmed">Content type</p><p class="font-mono text-sm text-toned">{{ detail.item.content_type }}</p></div>
        </div>
      </section>

      <section class="space-y-2">
        <h2 class="text-sm font-medium text-toned">Organised in</h2>
        <ul v-if="detail.stashes.length" class="space-y-1">
          <li v-for="stash in detail.stashes" :key="stash.id">
            <RouterLink :to="`/stashes/${stash.id}`" class="inline-flex items-center gap-1.5 text-sm text-muted hover:text-highlighted">
              <UIcon name="i-lucide-inbox" class="size-3.5 shrink-0 text-dimmed" />{{ stash.name }}
            </RouterLink>
          </li>
        </ul>
        <p v-else class="text-sm text-muted">No Stashes currently reference this item.</p>
      </section>

      <section v-if="detail.broadcasts.length" class="space-y-2">
        <h2 class="text-sm font-medium text-toned">Used by</h2>
        <ul class="space-y-1">
          <li v-for="broadcast in detail.broadcasts" :key="broadcast.id">
            <RouterLink :to="`/broadcasts/${broadcast.id}`" class="inline-flex items-center gap-1.5 text-sm text-muted hover:text-highlighted">
              <UIcon name="i-lucide-radio" class="size-3.5 shrink-0 text-dimmed" />{{ broadcast.name }} <span class="text-dimmed">· {{ broadcast.type }}</span>
            </RouterLink>
          </li>
        </ul>
      </section>
    </template>
  </main>
</template>
