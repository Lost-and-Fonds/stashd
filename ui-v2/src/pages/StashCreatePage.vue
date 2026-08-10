<script setup lang="ts">
/**
 * Happy-path creation only: one source, a name, and the sync mode for that
 * source's initial Input. Broadcasts are deliberately not part of this flow
 * — see planning/DECISIONS.md, "New stash workflow".
 */
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { FormError, FormSubmitEvent } from '@nuxt/ui'

import { inputFixtures } from '../fixtures/inputs'
import { stashFixtures } from '../fixtures/stashes'
import type { InputFixture, InputProvider, InputSyncMode } from '../types/input'
import type { StashFixture } from '../types/stash'

const router = useRouter()

interface DetectedSource {
  provider: InputProvider
  providerLabel: string
  icon: string
  suggestedName: string
}

function titleCase(value: string) {
  return value
    .split(/[\s._-]+/)
    .filter(Boolean)
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

// Local demo recognition only — not real provider detection. Mirrors the
// provider vocabulary already established in fixtures/input.ts.
function detectSource(url: string): DetectedSource | null {
  const trimmed = url.trim()
  if (!trimmed) return null

  const channel = trimmed.match(/youtube\.com\/@([\w.-]+)/i)
  if (channel) {
    return { provider: 'youtube-channel', providerLabel: 'YouTube channel', icon: 'i-lucide-youtube', suggestedName: titleCase(channel[1]) }
  }
  if (/youtube\.com\/playlist/i.test(trimmed)) {
    return { provider: 'youtube-playlist', providerLabel: 'YouTube playlist', icon: 'i-lucide-youtube', suggestedName: 'New YouTube Playlist' }
  }
  if (/\.xml($|[?#])/i.test(trimmed) || /\/feed\/?($|[?#])/i.test(trimmed)) {
    const host = trimmed.match(/^https?:\/\/(?:www\.)?([\w.-]+)/i)
    return { provider: 'rss', providerLabel: 'RSS feed', icon: 'i-lucide-rss', suggestedName: host ? titleCase(host[1].split('.')[0]) : 'New RSS Stash' }
  }
  return null
}

const form = reactive({
  sourceUrl: '',
  stashName: '',
  syncMode: 'automatic' as InputSyncMode,
  filterHint: ''
})

const nameTouched = ref(false)
const detected = computed(() => detectSource(form.sourceUrl))

function onSourceInput() {
  if (detected.value && !nameTouched.value) form.stashName = detected.value.suggestedName
}

const syncModeOptions = [
  { label: 'Automatic', description: 'Check for new items on a regular schedule.', value: 'automatic' as const },
  { label: 'Manual', description: 'Only sync when you trigger it yourself.', value: 'manual' as const }
]

const showAdvanced = ref(false)

function validate(state: Partial<typeof form>): FormError[] {
  const errors: FormError[] = []
  const url = (state.sourceUrl ?? '').trim()
  if (!url) {
    errors.push({ name: 'sourceUrl', message: 'Enter a source URL to continue.' })
  } else if (!detectSource(url)) {
    errors.push({ name: 'sourceUrl', message: 'We don’t recognize this link yet — try a YouTube channel, playlist, or RSS feed URL.' })
  }
  if (!(state.stashName ?? '').trim()) {
    errors.push({ name: 'stashName', message: 'Give this stash a name.' })
  }
  return errors
}

function onSubmit(event: FormSubmitEvent<typeof form>) {
  const source = detectSource(event.data.sourceUrl)!
  const nowIso = new Date().toISOString()
  const id = `stash-new-${stashFixtures.length + 1}`

  const stash: StashFixture = {
    id,
    name: event.data.stashName.trim(),
    itemCount: 0,
    sizeLabel: '0 GB',
    lastActivity: 'just now',
    lastActivityAt: nowIso,
    status: 'active',
    inputCount: 1,
    broadcastCount: 0
  }

  const input: InputFixture = {
    id: `${id}-input-1`,
    stashId: id,
    provider: source.provider,
    providerLabel: source.providerLabel,
    identity: event.data.stashName.trim(),
    url: event.data.sourceUrl.trim(),
    status: 'active',
    syncMode: event.data.syncMode,
    filterSummary: event.data.filterHint.trim() ? `Only titles containing "${event.data.filterHint.trim()}"` : undefined,
    lastChecked: 'just now',
    lastCheckedAt: nowIso
  }

  // Fixture-only demo persistence: mutate the shared in-memory arrays that
  // every other page already reads from. No store/repository layer for this.
  stashFixtures.push(stash)
  inputFixtures.push(input)

  router.push(`/stashes/${id}`)
}
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink to="/stashes" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Stashes
    </RouterLink>

    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">New stash</h1>
      <p class="text-sm text-muted">
        What do you want to preserve? Paste a link to a YouTube channel, playlist, or RSS feed to get started.
      </p>
    </header>

    <UForm :state="form" :validate="validate" class="space-y-8" @submit="onSubmit">
      <UFormField name="sourceUrl" label="Source URL" size="lg">
        <UInput
          v-model="form.sourceUrl"
          placeholder="https://youtube.com/@channel"
          icon="i-lucide-link"
          class="w-full font-mono"
          size="lg"
          autofocus
          @update:model-value="onSourceInput"
        />
        <p v-if="detected" class="mt-1.5 flex items-center gap-1.5 text-xs text-success">
          <UIcon :name="detected.icon" class="size-3.5" />
          {{ detected.providerLabel }} detected
        </p>
      </UFormField>

      <UFormField name="stashName" label="Stash name" size="lg" help="You can change this any time.">
        <UInput
          v-model="form.stashName"
          placeholder="e.g. Oculus Imperia"
          class="w-full font-mono"
          size="lg"
          @update:model-value="nameTouched = true"
        />
      </UFormField>

      <UFormField label="Sync" description="How Stashd checks this source for new items.">
        <URadioGroup v-model="form.syncMode" :items="syncModeOptions" />
      </UFormField>

      <UCollapsible v-model:open="showAdvanced">
        <UButton
          :label="showAdvanced ? 'Hide advanced settings' : 'Advanced settings'"
          :trailing-icon="showAdvanced ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
          variant="ghost"
          color="neutral"
          size="sm"
        />
        <template #content>
          <UFormField
            name="filterHint"
            label="Only include titles containing"
            help="Optional — leave blank to preserve everything from this source."
            class="mt-3"
          >
            <UInput v-model="form.filterHint" placeholder="e.g. field notes" class="w-full" />
          </UFormField>
        </template>
      </UCollapsible>

      <p class="text-xs text-dimmed">
        You can add podcast or media-server broadcasts after creating the stash.
      </p>

      <div class="flex items-center gap-2">
        <UButton label="Create stash" type="submit" size="lg" />
        <UButton label="Cancel" to="/stashes" variant="ghost" color="neutral" size="lg" />
      </div>
    </UForm>
  </main>
</template>
