<script setup lang="ts">
/**
 * Broadcast type → type-specific configuration → local fixture creation.
 * The type picker renders from `availableBroadcastTypes` rather than the
 * template assuming "Podcast or Media Server" — see planning/DECISIONS.md,
 * "New broadcast workflow". Podcast and media-server (Jellyfin/Plex) config
 * are deliberately separate sections with almost nothing forced in common;
 * see app/Broadcasts/Plugins/*.php in the PHP app for the real settings
 * these mirror.
 */
import { computed, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { FormError, FormSubmitEvent } from '@nuxt/ui'

import PreflightSummary from '../components/PreflightSummary.vue'
import { broadcastFixtures } from '../fixtures/broadcasts'
import { connectionFixtures } from '../fixtures/connections'
import { stashFixtures } from '../fixtures/stashes'
import type { BroadcastFixture, BroadcastKind } from '../types/broadcast'
import type { PreflightOperation, PreflightState, StorageEstimate } from '../types/preflight'

const route = useRoute()
const router = useRouter()

const stash = computed(() => stashFixtures.find(s => s.id === route.params.stashId))

// Available Broadcast types — a small fixture-backed list, not a hardcoded
// "Podcast or Media Server" assumption. `configComponent` says which config
// section below applies; Jellyfin and Plex are separate selectable types
// (they're separate plugins/outputs today) that happen to share one config
// section, since their settings genuinely are the same shape.
interface BroadcastTypeDescriptor {
  key: BroadcastKind
  label: string
  icon: string
  description: string
  configComponent: 'podcast' | 'media-server'
}

const availableBroadcastTypes: BroadcastTypeDescriptor[] = [
  {
    key: 'podcast',
    label: 'Podcast',
    icon: 'i-lucide-rss',
    description: 'A private audio or video feed you can subscribe to in a podcast app.',
    configComponent: 'podcast'
  },
  {
    key: 'jellyfin',
    label: 'Jellyfin',
    icon: 'i-lucide-tv',
    description: 'Publish preserved media into a connected Jellyfin library, organized like a TV series.',
    configComponent: 'media-server'
  },
  {
    key: 'plex',
    label: 'Plex',
    icon: 'i-lucide-tv',
    description: 'Publish preserved media into a connected Plex library, organized like a TV series.',
    configComponent: 'media-server'
  }
]

const typeItems = availableBroadcastTypes.map(t => ({ label: t.label, description: t.description, value: t.key, icon: t.icon }))

const form = reactive({
  typeKey: '' as '' | BroadcastKind,
  // Podcast — mirrors PodcastBroadcastPlugin::uiControls()
  podcastTitle: '',
  mediaKind: 'audio' as 'audio' | 'video',
  description: '',
  author: '',
  language: 'en',
  explicit: 'false' as 'false' | 'true',
  captions: 'off' as 'off' | 'creator_only' | 'creator_or_auto',
  captionLanguages: 'en',
  fundingUrl: '',
  complete: 'false' as 'false' | 'true',
  // Media-server (Jellyfin/Plex) — mirrors AbstractSeriesBroadcastPlugin +
  // MediaServerConnectionRecord/MediaServerLibrarySelection
  connectionId: '',
  libraryName: ''
})

const selectedType = computed(() => availableBroadcastTypes.find(t => t.key === form.typeKey))

const podcastTitleTouched = ref(false)
watch(() => form.typeKey, (key, previous) => {
  if (key === 'podcast' && !podcastTitleTouched.value) form.podcastTitle = stash.value?.name ?? ''
  // A connection valid for one media-server type is never valid for another.
  if (previous !== key) form.connectionId = ''
})

const connectionOptions = computed(() => connectionFixtures
  .filter(c => c.type === form.typeKey)
  .map(c => ({ label: c.name, value: c.id })))

const mediaKindOptions = [
  { label: 'Audio', value: 'audio' as const },
  { label: 'Video', value: 'video' as const }
]
const boolOptions = [
  { label: 'No', value: 'false' as const },
  { label: 'Yes', value: 'true' as const }
]
const captionsOptions = [
  { label: 'Off', value: 'off' as const },
  { label: 'Creator captions only', value: 'creator_only' as const },
  { label: 'Creator or auto-generated', value: 'creator_or_auto' as const }
]

const podcastAdvancedOpen = ref(false)
const mediaServerAdvancedOpen = ref(false)

// "What Stashd will do" — fixture-only analysis. Deliberately staged in two
// timers so the item/operation breakdown appears before the (slower, in
// reality) storage estimate — demonstrating that Preflight surfaces partial
// results rather than one blocking spinner.
function formatGB(value: number) {
  return `${value.toFixed(value < 10 ? 1 : 0)} GB`
}

function podcastTranscodeCount(mediaKind: 'audio' | 'video', total: number) {
  const ratio = mediaKind === 'video' ? 0.4 : 0.05
  return Math.min(total, Math.round(total * ratio))
}

function buildBroadcastOperations(type: BroadcastTypeDescriptor, mediaKind: 'audio' | 'video', total: number): PreflightOperation[] {
  if (type.configComponent === 'media-server') {
    return [{ key: 'hardlink', label: 'Hardlink', itemCount: total, storageLabel: 'no additional space', icon: 'i-lucide-link' }]
  }
  const transcodeCount = podcastTranscodeCount(mediaKind, total)
  const hardlinkCount = total - transcodeCount
  const perItemMid = mediaKind === 'video' ? 3.0 : 0.7
  const operations: PreflightOperation[] = []
  if (hardlinkCount > 0) operations.push({ key: 'hardlink', label: 'Hardlink', itemCount: hardlinkCount, storageLabel: 'no additional space', icon: 'i-lucide-link' })
  if (transcodeCount > 0) operations.push({ key: 'transcode', label: 'Transcode', itemCount: transcodeCount, storageLabel: `~${formatGB(transcodeCount * perItemMid)}`, icon: 'i-lucide-repeat' })
  return operations
}

function buildBroadcastStorage(type: BroadcastTypeDescriptor, mediaKind: 'audio' | 'video', total: number): StorageEstimate {
  if (type.configComponent === 'media-server') return { kind: 'none' }
  const transcodeCount = podcastTranscodeCount(mediaKind, total)
  if (transcodeCount === 0) return { kind: 'none' }
  const [low, high] = mediaKind === 'video' ? [2.6, 3.4] : [0.55, 0.85]
  return { kind: 'range', lowLabel: formatGB(transcodeCount * low), highLabel: formatGB(transcodeCount * high) }
}

const broadcastPreflight = ref<PreflightState | null>(null)
let broadcastItemsTimer: ReturnType<typeof setTimeout> | undefined
let broadcastStorageTimer: ReturnType<typeof setTimeout> | undefined

function clearBroadcastAnalysisTimers() {
  clearTimeout(broadcastItemsTimer)
  clearTimeout(broadcastStorageTimer)
}

function runBroadcastAnalysis() {
  clearBroadcastAnalysisTimers()
  broadcastPreflight.value = null

  const type = selectedType.value
  if (!type || !stash.value || stash.value.itemCount === 0) return

  const mediaKind = form.mediaKind
  const total = stash.value.itemCount
  const isStale = () => selectedType.value?.key !== type.key || (type.configComponent === 'podcast' && form.mediaKind !== mediaKind)

  broadcastItemsTimer = setTimeout(() => {
    if (isStale()) return
    const operations = buildBroadcastOperations(type, mediaKind, total)
    broadcastPreflight.value = {
      status: 'analyzing',
      plan: { itemCountLabel: `${total.toLocaleString()} items`, operations, storage: { kind: 'calculating' } }
    }

    broadcastStorageTimer = setTimeout(() => {
      if (isStale()) return
      const storage = buildBroadcastStorage(type, mediaKind, total)
      const transcodeCount = type.configComponent === 'podcast' ? podcastTranscodeCount(mediaKind, total) : 0
      broadcastPreflight.value = {
        status: 'ready',
        plan: {
          itemCountLabel: `${total.toLocaleString()} items`,
          operations,
          storage,
          notes: transcodeCount > 0 ? [`${transcodeCount} items require transcoding`] : undefined
        }
      }
    }, 900)
  }, 300)
}

watch(() => [form.typeKey, form.mediaKind], runBroadcastAnalysis, { immediate: true })
onUnmounted(clearBroadcastAnalysisTimers)

const broadcastCommitLabel = computed(() => broadcastPreflight.value?.status === 'analyzing' ? 'Create without estimate' : 'Create broadcast')

function validate(state: Partial<typeof form>): FormError[] {
  const errors: FormError[] = []
  if (!state.typeKey) {
    errors.push({ name: 'typeKey', message: 'Choose a broadcast type to continue.' })
    return errors
  }
  if (state.typeKey === 'podcast') {
    if (!state.podcastTitle?.trim()) errors.push({ name: 'podcastTitle', message: 'Give this podcast a title.' })
  } else {
    if (!state.connectionId) errors.push({ name: 'connectionId', message: 'Choose a connection.' })
    if (!state.libraryName?.trim()) errors.push({ name: 'libraryName', message: 'Enter the library name.' })
  }
  return errors
}

function onSubmit(event: FormSubmitEvent<typeof form>) {
  if (!stash.value || !selectedType.value) return
  const nowIso = new Date().toISOString()
  const id = `${stash.value.id}-broadcast-${broadcastFixtures.length + 1}-${Date.now()}`

  let name: string
  let formLabel: string
  let feedUrl: string | undefined

  if (selectedType.value.key === 'podcast') {
    name = `${event.data.podcastTitle.trim()} · Private feed`
    formLabel = `Podcast · ${event.data.mediaKind === 'video' ? 'Video' : 'Audio'}`
    // Feed tokens/URLs are generated once the broadcast actually builds —
    // not chosen at creation. See PodcastTokenService in the PHP app.
    feedUrl = undefined
  } else {
    name = `${stash.value.name} · ${selectedType.value.label}`
    formLabel = `${selectedType.value.label} · Media library`
  }

  const broadcast: BroadcastFixture = {
    id,
    stashId: stash.value.id,
    kind: selectedType.value.key,
    name,
    formLabel,
    status: 'active',
    buildState: 'stale',
    lastRebuild: 'never',
    lastRebuildAt: nowIso,
    itemsPublished: 0,
    itemsTotal: stash.value.itemCount,
    feedUrl
  }

  broadcastFixtures.push(broadcast)
  router.push(`/stashes/${stash.value.id}`)
}
</script>

<template>
  <main v-if="stash" class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink :to="`/stashes/${stash.id}`" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      {{ stash.name }}
    </RouterLink>

    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">New broadcast</h1>
      <p class="text-sm text-muted">What kind of output do you want for <span class="font-mono text-toned">{{ stash.name }}</span>?</p>
    </header>

    <UForm :state="form" :validate="validate" class="space-y-8" @submit="onSubmit">
      <UFormField name="typeKey">
        <URadioGroup v-model="form.typeKey" :items="typeItems" variant="card" size="lg">
          <template #label="{ item }">
            <span class="flex items-center gap-2">
              <UIcon :name="item.icon" class="size-4 text-muted" />
              <span class="font-medium text-highlighted">{{ item.label }}</span>
            </span>
          </template>
        </URadioGroup>
      </UFormField>

      <template v-if="selectedType">
        <!-- Podcast configuration -->
        <div v-if="selectedType.configComponent === 'podcast'" class="space-y-5">
          <UFormField name="podcastTitle" label="Podcast title">
            <UInput
              v-model="form.podcastTitle"
              class="w-full font-mono"
              size="lg"
              @update:model-value="podcastTitleTouched = true"
            />
          </UFormField>

          <UFormField label="Format" description="Whether episodes are audio-only or include video.">
            <URadioGroup v-model="form.mediaKind" :items="mediaKindOptions" orientation="horizontal" />
          </UFormField>

          <UCollapsible v-model:open="podcastAdvancedOpen">
            <UButton
              :label="podcastAdvancedOpen ? 'Hide advanced settings' : 'Advanced settings'"
              :trailing-icon="podcastAdvancedOpen ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              variant="ghost"
              color="neutral"
              size="sm"
            />
            <template #content>
              <div class="mt-3 space-y-4">
                <UFormField label="Description">
                  <UTextarea v-model="form.description" :rows="2" class="w-full" />
                </UFormField>
                <UFormField label="Author">
                  <UInput v-model="form.author" class="w-full" />
                </UFormField>
                <div class="grid grid-cols-2 gap-4">
                  <UFormField label="Language">
                    <UInput v-model="form.language" class="w-full" />
                  </UFormField>
                  <UFormField label="Explicit">
                    <USelect v-model="form.explicit" :items="boolOptions" value-key="value" class="w-full" />
                  </UFormField>
                </div>
                <div class="grid grid-cols-2 gap-4">
                  <UFormField label="Captions">
                    <USelect v-model="form.captions" :items="captionsOptions" value-key="value" class="w-full" />
                  </UFormField>
                  <UFormField label="Caption languages">
                    <UInput v-model="form.captionLanguages" class="w-full" />
                  </UFormField>
                </div>
                <UFormField label="Funding URL" help="Optional — shown to listeners as a way to support the source.">
                  <UInput v-model="form.fundingUrl" class="w-full font-mono" />
                </UFormField>
                <UFormField label="Mark feed as complete" description="Signals to podcast apps that no more episodes are coming.">
                  <USelect v-model="form.complete" :items="boolOptions" value-key="value" class="w-full sm:w-40" />
                </UFormField>
              </div>
            </template>
          </UCollapsible>
        </div>

        <!-- Media-server (Jellyfin/Plex) configuration -->
        <div v-else class="space-y-5">
          <UFormField name="connectionId" :label="`${selectedType.label} connection`">
            <USelect
              v-model="form.connectionId"
              :items="connectionOptions"
              value-key="value"
              placeholder="Select a connection"
              class="w-full"
            />
            <p v-if="connectionOptions.length === 0" class="mt-1.5 text-xs text-dimmed">
              No connected {{ selectedType.label }} server yet. Connections are configured separately.
            </p>
          </UFormField>

          <UFormField name="libraryName" label="Library" :help="`The library in ${selectedType.label} to publish into.`">
            <UInput v-model="form.libraryName" placeholder="e.g. TV Shows" class="w-full" />
          </UFormField>

          <UCollapsible v-model:open="mediaServerAdvancedOpen">
            <UButton
              :label="mediaServerAdvancedOpen ? 'Hide advanced settings' : 'Advanced settings'"
              :trailing-icon="mediaServerAdvancedOpen ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              variant="ghost"
              color="neutral"
              size="sm"
            />
            <template #content>
              <div class="mt-3 grid grid-cols-2 gap-4">
                <UFormField label="Captions">
                  <USelect v-model="form.captions" :items="captionsOptions" value-key="value" class="w-full" />
                </UFormField>
                <UFormField label="Caption languages">
                  <UInput v-model="form.captionLanguages" class="w-full" />
                </UFormField>
              </div>
            </template>
          </UCollapsible>
        </div>
      </template>

      <PreflightSummary v-if="broadcastPreflight" :state="broadcastPreflight" />

      <div class="space-y-2">
        <div class="flex items-center gap-2">
          <UButton :label="broadcastCommitLabel" type="submit" size="lg" :disabled="!form.typeKey" />
          <UButton label="Cancel" :to="`/stashes/${stash.id}`" variant="ghost" color="neutral" size="lg" />
        </div>
        <p v-if="broadcastPreflight?.status === 'analyzing'" class="text-xs text-dimmed">Storage estimate is still being calculated.</p>
      </div>
    </UForm>
  </main>

  <main v-else class="mx-auto max-w-2xl px-4 py-8 sm:px-8">
    <p class="text-sm text-muted">Stash not found.</p>
  </main>
</template>
