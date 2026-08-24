<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import type { FormError } from '@nuxt/ui'
import { useRoute } from 'vue-router'
import { broadcastSourceOptionValues, normalizeBroadcastSourceOptions } from '../adapters/normalizeBroadcastSourceOptions'
import { fetchBroadcast, updateBroadcastSourceSettings } from '../api/broadcasts'
import { fetchStashInputs } from '../api/inputs'
import PluginField from '../components/plugin/PluginField.vue'
import type { BroadcastApiResource, BroadcastOptionValue } from '../types/broadcast-plugin'
import type { StashInputApiResource } from '../types/input'
import type { PluginFieldValue } from '../types/plugin-ui'

const route = useRoute()
const broadcastId = String(route.params.broadcastId)
const sourceId = String(route.params.sourceId)
const broadcast = ref<BroadcastApiResource>()
const source = ref<StashInputApiResource>()
const loading = ref(true)
const saving = ref(false)
const error = ref<string>()
const saved = ref(false)
const values = reactive<Record<string, PluginFieldValue | undefined>>({})
const originalValues = ref<Record<string, BroadcastOptionValue>>({})

const normalized = computed(() => normalizeBroadcastSourceOptions(broadcast.value?.plugin_source_options ?? []))
const dirty = computed(() => normalized.value.fields.some(field => values[field.key] !== originalValues.value[field.key]))

function persistedSourceSettings(resource: BroadcastApiResource): Record<string, BroadcastOptionValue> {
  return { ...(resource.settings?.source_settings?.[sourceId] ?? {}) }
}

function resetValues(resource: BroadcastApiResource) {
  const next = broadcastSourceOptionValues(normalized.value.fields, persistedSourceSettings(resource))

  for (const key of Object.keys(values)) delete values[key]
  Object.assign(values, next)
  originalValues.value = { ...next }
}

async function load() {
  loading.value = true
  error.value = undefined

  try {
    const broadcastResource = await fetchBroadcast(broadcastId)
    const sourceResource = (await fetchStashInputs(broadcastResource.stash_id)).find(input => input.id === sourceId)

    if (!sourceResource) throw new Error('Source not found for this Broadcast.')

    broadcast.value = broadcastResource
    source.value = sourceResource
    resetValues(broadcastResource)
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not load source configuration.'
  } finally {
    loading.value = false
  }
}

function validate(state: Record<string, PluginFieldValue | undefined>): FormError[] {
  return normalized.value.fields
    .filter(field => field.required && (state[field.key] === undefined || state[field.key] === ''))
    .map(field => ({ name: field.key, message: `${field.label} is required.` }))
}

function sourceSettings(): Record<string, BroadcastOptionValue> {
  const settings = broadcast.value ? persistedSourceSettings(broadcast.value) : {}

  for (const field of normalized.value.fields) {
    const value = values[field.key]

    if (value !== originalValues.value[field.key] && (typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string')) {
      settings[field.key] = value
    }
  }

  return settings
}

async function save() {
  if (!broadcast.value || normalized.value.diagnostics.length > 0 || !dirty.value) return

  saving.value = true
  error.value = undefined
  saved.value = false

  try {
    const updated = await updateBroadcastSourceSettings(broadcast.value.id, sourceId, sourceSettings())
    broadcast.value = updated
    resetValues(updated)
    saved.value = true
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not save source configuration.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink :to="`/broadcasts/${broadcastId}`" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Broadcasts
    </RouterLink>

    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">Source configuration</h1>
      <p v-if="source" class="text-sm text-muted">Plugin-declared settings for <span class="font-mono text-toned">{{ source.title ?? source.provider_input_id }}</span>.</p>
      <p v-else class="text-sm text-muted">Plugin-declared settings for this Broadcast source.</p>
    </header>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
      Loading source configuration…
    </div>

    <template v-else>
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not update source configuration" :description="error" />

      <template v-if="broadcast && source">
        <UAlert
          v-if="normalized.diagnostics.length"
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Some declared settings are unsupported"
          :description="normalized.diagnostics.map(diagnostic => diagnostic.message).join(' ')"
        />

        <UForm :state="values" :validate="validate" class="space-y-6" @submit="save">
          <UCard :ui="{ body: 'p-4 sm:p-6' }">
            <div class="space-y-5">
              <PluginField
                v-for="field in normalized.fields"
                :key="field.key"
                v-model="values[field.key]"
                :field="field"
              />
              <p v-if="normalized.fields.length === 0" class="text-sm text-muted">This Broadcast does not declare supported source settings.</p>
            </div>
          </UCard>

          <div class="flex items-center gap-2">
            <UButton label="Save changes" type="submit" :loading="saving" :disabled="!dirty || normalized.diagnostics.length > 0" />
            <UButton label="Discard changes" variant="ghost" color="neutral" :disabled="!dirty || saving" @click="broadcast && resetValues(broadcast)" />
            <span v-if="saved" class="text-sm text-success">Saved.</span>
          </div>
        </UForm>
      </template>
    </template>
  </main>
</template>
