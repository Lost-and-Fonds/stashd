<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import type { FormError } from '@nuxt/ui'
import { useRoute, useRouter } from 'vue-router'
import { normalizeBroadcastPreview } from '../adapters/normalizeCreationPreflight'
import { broadcastOptionValues, normalizeBroadcastOptions } from '../adapters/normalizeBroadcastOptions'
import { createStashBroadcast, fetchBroadcastPlugins, fetchConnectionLibraries, previewBroadcast } from '../api/broadcasts'
import { fetchConnections } from '../api/connections'
import { fetchStash } from '../api/stashes'
import PluginField from '../components/plugin/PluginField.vue'
import PreflightSummary from '../components/PreflightSummary.vue'
import type { BroadcastOptionValue, BroadcastPluginApiResource, CreatedBroadcastApiResource } from '../types/broadcast-plugin'
import type { StashApiResource } from '../types/stash'
import type { PluginFieldValue } from '../types/plugin-ui'
import type { ConnectionApiResource } from '../types/connection'
import type { PreflightState } from '../types/preflight'

const route = useRoute()
const router = useRouter()
const stashId = String(route.params.stashId)
const stash = ref<StashApiResource>()
const broadcastName = ref('')
const plugins = ref<BroadcastPluginApiResource[]>([])
const connections = ref<ConnectionApiResource[]>([])
const libraries = ref<{ value: string, label: string, kind?: string }[]>([])
const loadingLibraries = ref(false)
const loading = ref(true)
const saving = ref(false)
const error = ref<string>()
const created = ref<CreatedBroadcastApiResource>()
const typeKey = ref('')
const values = reactive<Record<string, PluginFieldValue | undefined>>({})
const preview = ref<PreflightState>()
const previewError = ref<string>()
let previewGeneration = 0
let previewTimer: ReturnType<typeof setTimeout> | undefined

const selectedPlugin = computed(() => plugins.value.find(plugin => plugin.key === typeKey.value))
const normalized = computed(() => normalizeBroadcastOptions(selectedPlugin.value?.ui_controls ?? []))
const connectionKey = computed(() => selectedPlugin.value?.connection_setting_key ?? null)
const libraryKey = computed(() => selectedPlugin.value?.library_setting_key ?? null)
const connectionField = computed(() => connectionKey.value === null ? null : normalized.value.fields.find(field => field.key === connectionKey.value) ?? null)
const settingFields = computed(() => normalized.value.fields.filter(field => field.key !== connectionKey.value))
const connectionValue = computed<string | undefined>({
  get: () => connectionKey.value === null ? undefined : typeof values[connectionKey.value] === 'string' ? values[connectionKey.value] as string : undefined,
  set: (value: string | undefined) => { if (connectionKey.value !== null) values[connectionKey.value] = value }
})
const libraryValue = computed<string | undefined>({
  get: () => libraryKey.value === null ? undefined : typeof values[libraryKey.value] === 'string' ? values[libraryKey.value] as string : undefined,
  set: (value: string | undefined) => { if (libraryKey.value !== null) values[libraryKey.value] = value }
})
const mediaKind = computed(() => {
  const value = values.media_kind ?? values.mediaKind
  return typeof value === 'string' && value !== '' ? value : undefined
})
const typeItems = computed(() => plugins.value.map(plugin => ({
  label: plugin.label,
  description: plugin.description ?? '',
  value: plugin.key,
  icon: 'i-lucide-box'
})))

function resetValues() {
  for (const key of Object.keys(values)) delete values[key]
  Object.assign(values, broadcastOptionValues(normalized.value.fields))
  libraries.value = []
}

watch(typeKey, () => {
  error.value = undefined
  created.value = undefined
  preview.value = undefined
  previewError.value = undefined
  previewGeneration++
  resetValues()
})

watch([typeKey, mediaKind], () => {
  if (previewTimer) clearTimeout(previewTimer)
  previewTimer = setTimeout(() => { void requestPreview() }, 100)
})

watch(() => values[connectionKey.value ?? ''], async (connectionId) => {
  libraries.value = []
  if (typeof connectionId !== 'string' || connectionId === '' || libraryKey.value === null) return

  loadingLibraries.value = true
  error.value = undefined
  delete values[libraryKey.value]

  try {
    libraries.value = await fetchConnectionLibraries(connectionId)
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not discover media libraries.'
  } finally {
    loadingLibraries.value = false
  }
})

async function load() {
  loading.value = true
  error.value = undefined

  try {
    const [stashResource, pluginResources, connectionResources] = await Promise.all([fetchStash(stashId), fetchBroadcastPlugins(), fetchConnections()])
    stash.value = stashResource
    broadcastName.value = stashResource.name
    plugins.value = pluginResources
    connections.value = connectionResources
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not load broadcast configuration.'
  } finally {
    loading.value = false
  }
}

function validate(state: Record<string, PluginFieldValue | undefined>): FormError[] {
  const fields = normalized.value.fields.filter(field => field.key !== libraryKey.value)
  const errors = fields
    .filter(field => field.required && (state[field.key] === undefined || state[field.key] === ''))
    .map(field => ({ name: field.key, message: `${field.label} is required.` }))

  if (broadcastName.value.trim() === '') errors.push({ name: 'name', message: 'Title is required.' })

  if (libraryKey.value !== null && connectionKey.value !== null && typeof state[connectionKey.value] === 'string' && state[connectionKey.value] !== '' && (state[libraryKey.value] === undefined || state[libraryKey.value] === '')) {
    errors.push({ name: libraryKey.value, message: 'Choose a discovered library.' })
  }

  return errors
}

function settings(): Record<string, BroadcastOptionValue> {
  const result = Object.fromEntries(normalized.value.fields.flatMap(field => {
    const value = values[field.key]

    return typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string' ? [[field.key, value]] : []
  }))

  if (libraryKey.value !== null && typeof values[libraryKey.value] === 'string') {
    result[libraryKey.value] = values[libraryKey.value] as string
  }

  return result
}

async function requestPreview() {
  if (!stash.value || !selectedPlugin.value) return
  const generation = ++previewGeneration
  previewError.value = undefined
  preview.value = { status: 'analyzing', plan: { operations: [], storage: { kind: 'calculating' }, notes: [] } }

  try {
    const result = await previewBroadcast(stash.value.id, selectedPlugin.value.key, mediaKind.value)
    if (generation !== previewGeneration) return
    preview.value = normalizeBroadcastPreview(result)
  } catch (exception) {
    if (generation === previewGeneration) {
      preview.value = undefined
      previewError.value = exception instanceof Error ? exception.message : 'Broadcast planning is unavailable.'
    }
  }
}

async function create() {
  if (!stash.value || !selectedPlugin.value || normalized.value.diagnostics.length) return

  saving.value = true
  error.value = undefined
  created.value = undefined

  try {
    created.value = await createStashBroadcast(stash.value.id, selectedPlugin.value.key, settings(), broadcastName.value.trim())
    await router.push(`/stashes/${stash.value.id}`)
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not create broadcast.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => { if (previewTimer) clearTimeout(previewTimer); previewGeneration++ })
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink :to="`/stashes/${stashId}`" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Stash
    </RouterLink>

    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">New broadcast</h1>
      <p class="text-sm text-muted">Choose an output for <span v-if="stash" class="font-mono text-toned">{{ stash.name }}</span><span v-else>this Stash</span>.</p>
    </header>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
      Loading broadcast types…
    </div>

    <template v-else>
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not create broadcast" :description="error" />

      <UForm v-if="stash" :state="values" :validate="validate" class="space-y-6" @submit="create">
        <UFormField name="name" label="Title" required>
          <UInput v-model="broadcastName" placeholder="Broadcast title" class="w-full" />
        </UFormField>
        <UFormField name="type">
          <URadioGroup v-model="typeKey" :items="typeItems" variant="card" size="lg" />
        </UFormField>

        <template v-if="selectedPlugin">
          <UAlert
            v-if="normalized.diagnostics.length"
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Some declared settings are unsupported"
            :description="normalized.diagnostics.map(diagnostic => diagnostic.message).join(' ')"
          />

          <UCard :ui="{ body: 'p-4 sm:p-6' }">
            <div class="space-y-5">
              <PluginField
                v-for="field in settingFields"
                :key="field.key"
                v-model="values[field.key]"
                :field="field"
              />
              <UFormField v-if="connectionKey && connectionField" :name="connectionKey" :label="connectionField.label" :description="connectionField.description" required>
                <USelect
                  v-model="connectionValue"
                  :items="connections.filter(connection => connection.plugin_key === selectedPlugin?.key).map(connection => ({ label: connection.name, value: connection.id }))"
                  value-key="value"
                  label-key="label"
                  placeholder="Choose a Connection"
                />
                <NuxtLink v-if="connections.filter(connection => connection.plugin_key === selectedPlugin?.key).length === 0" to="/connections" class="mt-1 block text-xs text-primary">Configure a Connection first.</NuxtLink>
              </UFormField>
              <UFormField v-if="libraryKey && connectionKey && values[connectionKey]" :name="libraryKey" label="Library" required>
                <USelect v-model="libraryValue" :items="libraries" value-key="value" label-key="label" :loading="loadingLibraries" placeholder="Choose a discovered library" />
                <p v-if="!loadingLibraries && libraries.length === 0" class="mt-1 text-xs text-muted">No compatible libraries were discovered.</p>
              </UFormField>
              <p v-if="settingFields.length === 0 && !connectionKey" class="text-sm text-muted">This Broadcast type has no configurable settings.</p>
            </div>
          </UCard>
          <PreflightSummary v-if="preview" :state="preview" />
          <UAlert v-if="previewError" color="warning" variant="subtle" icon="i-lucide-info" title="Planning unavailable" :description="`${previewError} You can still create this Broadcast.`" />
        </template>

        <UAlert
          v-if="created"
          color="success"
          variant="subtle"
          icon="i-lucide-circle-check"
          title="Broadcast created"
          :description="`${created.name} was created and its first rebuild was queued.`"
        />

        <div class="flex gap-2">
          <UButton label="Create broadcast" type="submit" size="lg" :loading="saving" :disabled="!selectedPlugin || normalized.diagnostics.length > 0" />
          <UButton label="Cancel" variant="ghost" color="neutral" size="lg" :disabled="saving" @click="router.push(`/stashes/${stash.id}`)" />
        </div>
      </UForm>
    </template>
  </main>
</template>
