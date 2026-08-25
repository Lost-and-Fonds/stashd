<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { inputOptionValues, normalizeInputOptions } from '../adapters/normalizeInputOptions'
import { normalizeSourceFields } from '../adapters/normalizeSourceFields'
import { normalizeStashPreflight } from '../adapters/normalizeCreationPreflight'
import { createStashWithInput, fetchInputPlugins, preflightInputPlugin, type InputPluginApiResource, type ResolvedSource } from '../api/inputPlugins'
import { fetchStashPreflightReview, preflightStash } from '../api/stashes'
import PluginField from '../components/plugin/PluginField.vue'
import PreflightSummary from '../components/PreflightSummary.vue'
import { subscribeLiveUpdates, type LiveEvent } from '../live/mercure'
import type { PluginFieldValue } from '../types/plugin-ui'
import type { PreflightState } from '../types/preflight'

const router = useRouter()
const plugins = ref<InputPluginApiResource[]>([])
const pluginKey = ref('')
const sourceValues = reactive<Record<string, PluginFieldValue | undefined>>({})
const optionValues = reactive<Record<string, PluginFieldValue | undefined>>({})
const stashName = ref('')
const nameTouched = ref(false)
const resolved = ref<ResolvedSource>()
const preflight = ref<PreflightState>()
const preflightError = ref<string>()
const loading = ref(true)
const resolving = ref(false)
const saving = ref(false)
const error = ref<string>()
const selected = computed(() => plugins.value.find(plugin => plugin.key === pluginKey.value))
const source = computed(() => normalizeSourceFields(selected.value?.source_fields ?? []))
const inputOptions = computed(() => normalizeInputOptions(selected.value?.input_options ?? []))
const canResolve = computed(() => selected.value && source.value.diagnostics.length === 0 && source.value.fields.every(field => !field.required || (sourceValues[field.key] !== undefined && sourceValues[field.key] !== '')))
let preflightGeneration = 0
let preflightCommandId: string | undefined
let unsubscribeLive: (() => void) | undefined

function clear(values: Record<string, PluginFieldValue | undefined>) { for (const key of Object.keys(values)) delete values[key] }
function invalidate() {
  resolved.value = undefined
  preflight.value = undefined
  preflightError.value = undefined
  preflightCommandId = undefined
  preflightGeneration++
  error.value = undefined
}
watch(pluginKey, () => { clear(sourceValues); clear(optionValues); Object.assign(optionValues, inputOptionValues(inputOptions.value.fields)); invalidate(); nameTouched.value = false; stashName.value = '' })
watch(sourceValues, invalidate, { deep: true })

function sourcePayload(): Record<string, boolean | number | string> {
  return Object.fromEntries(source.value.fields.flatMap(field => {
    const value = sourceValues[field.key]
    return typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string' ? [[field.key, value]] : []
  }))
}
function optionPayload(): Record<string, boolean | string> {
  return Object.fromEntries(Object.entries(optionValues).filter((entry): entry is [string, boolean | string] => typeof entry[1] === 'boolean' || typeof entry[1] === 'string'))
}
async function resolve() {
  if (!selected.value || !canResolve.value) return
  const generation = ++preflightGeneration
  resolving.value = true; error.value = undefined; preflight.value = undefined; preflightError.value = undefined; preflightCommandId = undefined
  try {
    const nextResolved = await preflightInputPlugin(selected.value.key, sourcePayload())
    if (generation !== preflightGeneration) return
    resolved.value = nextResolved
    if (!nameTouched.value && resolved.value.display_name) stashName.value = resolved.value.display_name
    await requestStashPreflight(generation)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Could not validate this source.' }
  finally { resolving.value = false }
}

async function requestStashPreflight(generation: number) {
  if (!resolved.value) return
  preflight.value = { status: 'analyzing', plan: { operations: [], storage: { kind: 'calculating' }, notes: [] } }

  try {
    const accepted = await preflightStash(resolved.value.canonical_reference, resolved.value.display_name)
    if (generation !== preflightGeneration) return
    preflightCommandId = accepted.command_id
    await receiveStashPreflight(generation, accepted.command_id)
  } catch (exception) {
    if (generation === preflightGeneration) {
      preflight.value = undefined
      preflightError.value = exception instanceof Error ? exception.message : 'Authoritative planning is unavailable.'
    }
  }
}

async function receiveStashPreflight(generation: number, commandId: string) {
  try {
    const review = await fetchStashPreflightReview(commandId)
    if (generation !== preflightGeneration) return
    if (review.state === 'completed' && review.preflight) preflight.value = normalizeStashPreflight(review)
    else if (review.state === 'failed') preflightError.value = 'Authoritative planning could not be completed.'
  } catch (exception) {
    if (generation === preflightGeneration) preflightError.value = exception instanceof Error ? exception.message : 'Authoritative planning is unavailable.'
  }
}

function handleLiveEvent(event: LiveEvent) {
  if (!preflightCommandId || !event.event.startsWith('job.')) return
  const commandId = event.payload.commandId ?? event.payload.command_id
  if (commandId !== preflightCommandId) return
  if (event.event === 'job.completed') void receiveStashPreflight(preflightGeneration, preflightCommandId)
  if (event.event === 'job.failed') {
    preflightError.value = 'Authoritative planning could not be completed.'
    preflight.value = undefined
  }
}
async function create() {
  if (!selected.value || !resolved.value || !stashName.value.trim()) return
  saving.value = true; error.value = undefined
  try { const stash = await createStashWithInput(stashName.value.trim(), selected.value.key, sourcePayload(), optionPayload()); await router.push(`/stashes/${stash.id}`) }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Could not create this stash.' }
  finally { saving.value = false }
}
onMounted(async () => { unsubscribeLive = subscribeLiveUpdates(handleLiveEvent); try { plugins.value = await fetchInputPlugins() } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Could not load input types.' } finally { loading.value = false } })
onBeforeUnmount(() => { unsubscribeLive?.(); preflightGeneration++ })
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink to="/stashes" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted"><UIcon name="i-lucide-arrow-left" class="size-3.5" />Stashes</RouterLink>
    <header class="space-y-1.5"><h1 class="text-2xl font-semibold text-highlighted">New stash</h1><p class="text-sm text-muted">Choose what this Stash should follow.</p></header>
    <div v-if="loading" class="text-sm text-muted">Loading input types…</div>
    <template v-else>
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not create stash" :description="error" />
      <UForm class="space-y-6" @submit.prevent="create">
        <UFormField label="Input type" required><URadioGroup v-model="pluginKey" :items="plugins.map(plugin => ({ label: plugin.label, description: '', value: plugin.key }))" variant="card" size="lg" /></UFormField>
        <template v-if="selected">
          <UAlert v-if="source.diagnostics.length" color="warning" variant="subtle" title="Unsupported source fields" :description="source.diagnostics.join(' ')" />
          <div class="space-y-5"><PluginField v-for="field in source.fields" :key="field.key" v-model="sourceValues[field.key]" :field="field" /></div>
          <UButton label="Validate source" type="button" :loading="resolving" :disabled="!canResolve || Boolean(resolved)" @click="resolve" />
          <template v-if="resolved">
            <UAlert color="success" variant="subtle" icon="i-lucide-circle-check" title="Source ready" :description="resolved.display_name ?? undefined" />
            <PreflightSummary v-if="preflight" :state="preflight" />
            <UAlert v-if="preflightError" color="warning" variant="subtle" icon="i-lucide-info" title="Planning unavailable" :description="`${preflightError} You can still create this Stash.`" />
            <UFormField label="Stash name" required><UInput v-model="stashName" class="w-full" @update:model-value="nameTouched = true" /></UFormField>
            <UAlert v-if="inputOptions.diagnostics.length" color="warning" variant="subtle" title="Unsupported input options" :description="inputOptions.diagnostics.map(diagnostic => diagnostic.message).join(' ')" />
            <div v-else class="space-y-5"><PluginField v-for="field in inputOptions.fields" :key="field.key" v-model="optionValues[field.key]" :field="field" /></div>
            <div class="flex items-center gap-2"><UButton label="Create stash" type="submit" size="lg" :loading="saving" :disabled="!stashName.trim()" /><UButton label="Cancel" to="/stashes" variant="ghost" color="neutral" size="lg" /></div>
          </template>
        </template>
      </UForm>
    </template>
  </main>
</template>
