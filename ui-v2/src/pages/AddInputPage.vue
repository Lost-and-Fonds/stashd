<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { inputOptionValues, normalizeInputOptions } from '../adapters/normalizeInputOptions'
import { normalizeSourceFields } from '../adapters/normalizeSourceFields'
import { fetchInputPlugins, preflightInputPlugin, type InputPluginApiResource, type ResolvedSource } from '../api/inputPlugins'
import { addInputToStash } from '../api/inputs'
import { fetchStash } from '../api/stashes'
import PluginField from '../components/plugin/PluginField.vue'
import type { PluginFieldValue } from '../types/plugin-ui'
import type { StashApiResource } from '../types/stash'

const route = useRoute()
const router = useRouter()
const stashId = String(route.params.stashId)
const stash = ref<StashApiResource>()
const plugins = ref<InputPluginApiResource[]>([])
const pluginKey = ref('')
const sourceValues = reactive<Record<string, PluginFieldValue | undefined>>({})
const optionValues = reactive<Record<string, PluginFieldValue | undefined>>({})
const resolved = ref<ResolvedSource>()
const loading = ref(true)
const resolving = ref(false)
const saving = ref(false)
const error = ref<string>()
const selected = computed(() => plugins.value.find(plugin => plugin.key === pluginKey.value))
const source = computed(() => normalizeSourceFields(selected.value?.source_fields ?? []))
const options = computed(() => normalizeInputOptions(selected.value?.input_options ?? []))
const canResolve = computed(() => Boolean(selected.value) && source.value.diagnostics.length === 0 && source.value.fields.every(field => !field.required || (sourceValues[field.key] !== undefined && sourceValues[field.key] !== '')))

function clear(values: Record<string, PluginFieldValue | undefined>) { for (const key of Object.keys(values)) delete values[key] }
function payload(values: Record<string, PluginFieldValue | undefined>, allowNumber = true): Record<string, boolean | number | string> {
  return Object.fromEntries(Object.entries(values).filter((entry): entry is [string, boolean | number | string] => typeof entry[1] === 'boolean' || typeof entry[1] === 'string' || allowNumber && typeof entry[1] === 'number'))
}
watch(pluginKey, () => { clear(sourceValues); clear(optionValues); Object.assign(optionValues, inputOptionValues(options.value.fields)); resolved.value = undefined; error.value = undefined })
watch(sourceValues, () => { resolved.value = undefined }, { deep: true })
async function resolve() {
  if (!selected.value || !canResolve.value) return
  resolving.value = true; error.value = undefined
  try { resolved.value = await preflightInputPlugin(selected.value.key, payload(sourceValues)) }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Could not validate this source.' }
  finally { resolving.value = false }
}
async function create() {
  if (!selected.value || !resolved.value) return
  saving.value = true; error.value = undefined
  try { await addInputToStash(stashId, selected.value.key, payload(sourceValues), payload(optionValues, false) as Record<string, boolean | string>); await router.push(`/stashes/${encodeURIComponent(stashId)}`) }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Could not add this Input.' }
  finally { saving.value = false }
}
onMounted(async () => { try { [stash.value, plugins.value] = await Promise.all([fetchStash(stashId), fetchInputPlugins()]) } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Could not load Add Input.' } finally { loading.value = false } })
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink :to="`/stashes/${stashId}`" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted"><UIcon name="i-lucide-arrow-left" class="size-3.5" />Stash</RouterLink>
    <header class="space-y-1.5"><h1 class="text-2xl font-semibold text-highlighted">Add Input</h1><p class="text-sm text-muted">Choose another source for <span class="font-mono text-toned">{{ stash?.name ?? 'this Stash' }}</span>.</p></header>
    <div v-if="loading" class="text-sm text-muted">Loading Input types…</div>
    <template v-else>
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not add Input" :description="error" />
      <UForm class="space-y-6" @submit.prevent="create">
        <UFormField label="Input type" required><URadioGroup v-model="pluginKey" :items="plugins.map(plugin => ({ label: plugin.label, description: '', value: plugin.key }))" variant="card" size="lg" /></UFormField>
        <template v-if="selected">
          <UAlert v-if="source.diagnostics.length" color="warning" variant="subtle" title="Unsupported source fields" :description="source.diagnostics.join(' ')" />
          <div class="space-y-5"><PluginField v-for="field in source.fields" :key="field.key" v-model="sourceValues[field.key]" :field="field" /></div>
          <UButton label="Validate source" type="button" :loading="resolving" :disabled="!canResolve || Boolean(resolved)" @click="resolve" />
          <template v-if="resolved">
            <UAlert color="success" variant="subtle" icon="i-lucide-circle-check" title="Source ready" :description="resolved.display_name ?? undefined" />
            <UAlert v-if="options.diagnostics.length" color="warning" variant="subtle" title="Unsupported Input options" :description="options.diagnostics.map(diagnostic => diagnostic.message).join(' ')" />
            <div v-else class="space-y-5"><PluginField v-for="field in options.fields" :key="field.key" v-model="optionValues[field.key]" :field="field" /></div>
            <div class="flex items-center gap-2"><UButton label="Add Input" type="submit" size="lg" :loading="saving" :disabled="options.diagnostics.length > 0" /><UButton :to="`/stashes/${stashId}`" label="Cancel" variant="ghost" color="neutral" size="lg" /></div>
          </template>
        </template>
      </UForm>
    </template>
  </main>
</template>
