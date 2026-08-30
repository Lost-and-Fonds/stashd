<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import type { FormError } from '@nuxt/ui'
import { inputOptionValues, normalizeInputOptions } from '../adapters/normalizeInputOptions'
import { fetchStashInput, updateStashInputOptions } from '../api/inputs'
import PluginField from '../components/plugin/PluginField.vue'
import type { InputOptionValue, StashInputApiResource } from '../types/input'
import type { PluginFieldValue } from '../types/plugin-ui'

const route = useRoute()
const stashId = String(route.params.stashId)
const inputId = String(route.params.inputId)
const input = ref<StashInputApiResource>()
const loading = ref(true)
const saving = ref(false)
const error = ref<string>()
const saved = ref(false)
const values = reactive<Record<string, PluginFieldValue | undefined>>({})
const originalValues = ref<Record<string, InputOptionValue>>({})
const filters = reactive({ include: '', exclude: '' })

const normalized = computed(() => input.value ? normalizeInputOptions(input.value.input_options) : { fields: [], diagnostics: [] })
const dirty = computed(() => normalized.value.fields.some(field => values[field.key] !== originalValues.value[field.key]) || filters.include !== (input.value?.options?.title_regex_include ?? '') || filters.exclude !== (input.value?.options?.title_regex_exclude ?? ''))

function resetValues(resource: StashInputApiResource) {
  const next = inputOptionValues(normalized.value.fields, resource.options?.provider)

  for (const key of Object.keys(values)) delete values[key]
  Object.assign(values, next)
  originalValues.value = { ...next }
  filters.include = resource.options?.title_regex_include ?? ''
  filters.exclude = resource.options?.title_regex_exclude ?? ''
}

async function load() {
  loading.value = true
  error.value = undefined

  try {
    input.value = await fetchStashInput(stashId, inputId)
    resetValues(input.value)
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not load this Input.'
  } finally {
    loading.value = false
  }
}

function validate(state: Record<string, PluginFieldValue | undefined>): FormError[] {
  const errors = normalized.value.fields
    .filter(field => field.required && (state[field.key] === undefined || state[field.key] === ''))
    .map(field => ({ name: field.key, message: `${field.label} is required.` }))

  for (const [name, value] of [['title_regex_include', filters.include], ['title_regex_exclude', filters.exclude] ] as const) {
    if (!value) continue
    try { new RegExp(value) } catch { errors.push({ name, message: 'That does not look like a valid regular expression.' }) }
  }

  return errors
}

function providerOptions(): Record<string, InputOptionValue> {
  const provider = { ...(input.value?.options?.provider ?? {}) }

  for (const field of normalized.value.fields) {
    const value = values[field.key]

    if (value !== originalValues.value[field.key] && (typeof value === 'boolean' || typeof value === 'string')) {
      provider[field.key] = value
    }
  }

  return provider
}

async function save() {
  if (!input.value || normalized.value.diagnostics.length > 0 || !dirty.value) return

  saving.value = true
  error.value = undefined
  saved.value = false

  try {
    const updated = await updateStashInputOptions(stashId, inputId, {
      ...(input.value.options ?? {}),
      title_regex_include: filters.include.trim() || null,
      title_regex_exclude: filters.exclude.trim() || null,
      provider: providerOptions()
    })
    input.value = updated
    resetValues(updated)
    saved.value = true
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not save Input options.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink :to="`/stashes/${stashId}`" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Stash
    </RouterLink>

    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">Input configuration</h1>
      <p class="text-sm text-muted">Plugin-declared settings for this existing Input.</p>
    </header>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
      Loading Input configuration…
    </div>

    <template v-else>
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not update Input configuration" :description="error" />

      <template v-if="input">
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
            <UFormField label="Include title regex" description="Only matching titles are preserved."><UInput v-model="filters.include" placeholder="e.g. season 2|s02" /></UFormField>
            <UFormField label="Exclude title regex" description="Matching titles are ignored."><UInput v-model="filters.exclude" placeholder="e.g. trailer|shorts" /></UFormField>
            <PluginField
              v-for="field in normalized.fields"
              :key="field.key"
              v-model="values[field.key]"
              :field="field"
            />
            <p v-if="normalized.fields.length === 0" class="text-sm text-muted">This Input has no supported configurable settings.</p>
          </div>
        </UCard>

        <div class="flex items-center gap-2">
          <UButton label="Save changes" type="submit" :loading="saving" :disabled="!dirty || normalized.diagnostics.length > 0" />
          <UButton label="Discard changes" variant="ghost" color="neutral" :disabled="!dirty || saving" @click="input && resetValues(input)" />
          <span v-if="saved" class="text-sm text-success">Saved.</span>
        </div>
      </UForm>
      </template>
    </template>
  </main>
</template>
