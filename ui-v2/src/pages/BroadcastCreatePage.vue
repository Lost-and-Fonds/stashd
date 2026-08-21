<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { FormError, FormSubmitEvent } from '@nuxt/ui'
import PreflightSummary from '../components/PreflightSummary.vue'
import { broadcastFixtures } from '../fixtures/broadcasts'
import { connectionFixtures } from '../fixtures/connections'
import { stashFixtures } from '../fixtures/stashes'
import type { BroadcastFixture, BroadcastKind } from '../types/broadcast'
import type { PreflightOperation, PreflightState } from '../types/preflight'

type Control = { key: string; label: string; type: string; default?: string | boolean; required?: boolean; options?: string[] }
type BroadcastTypeDescriptor = { key: BroadcastKind; label: string; icon: string; description: string; configComponent: 'plugin' | 'media-server'; controls?: Control[] }
const route = useRoute(); const router = useRouter()
const stash = computed(() => stashFixtures.find(s => s.id === route.params.stashId))

// Production descriptors come from the plugin API. The fixture uses the same
// data shape so this renderer never branches on a provider name.
const availableBroadcastTypes = ref<BroadcastTypeDescriptor[]>([])
const typeItems = computed(() => availableBroadcastTypes.value.map(type => ({ label: type.label, description: type.description, value: type.key, icon: type.icon })))
onMounted(async () => {
  const response = await fetch('/api/v1/broadcast-plugins')
  if (!response.ok) return
  const body = await response.json() as { plugins?: Array<{ key: string; label: string; description?: string; ui_controls?: Array<{ name: string; label: string; type: string; default?: string | boolean; options?: string[]; required?: boolean }> }> }
  availableBroadcastTypes.value = (body.plugins ?? []).map(plugin => ({
    key: plugin.key as BroadcastKind,
    label: plugin.label,
    icon: plugin.key === 'jellyfin' || plugin.key === 'plex' ? 'i-lucide-tv' : 'i-lucide-box',
    description: plugin.description ?? '',
    configComponent: plugin.key === 'jellyfin' || plugin.key === 'plex' ? 'media-server' : 'plugin',
    controls: plugin.ui_controls?.map(control => ({ key: control.name, label: control.label, type: control.type, default: control.default, options: control.options, required: control.required })),
  }))
})
const selectedType = computed(() => availableBroadcastTypes.value.find(type => type.key === form.typeKey))
const form = reactive({ typeKey: '' as '' | BroadcastKind, settings: {} as Record<string, string | boolean>, connectionId: '', libraryName: '' })
watch(() => form.typeKey, (key, previous) => {
  const controls = availableBroadcastTypes.value.find(type => type.key === key)?.controls ?? []
  form.settings = Object.fromEntries(controls.map(control => [control.key, control.default ?? '']))
  if (controls.some(control => control.key === 'title') && !form.settings.title) form.settings.title = stash.value?.name ?? ''
  if (previous !== key) form.connectionId = ''
})
const connectionOptions = computed(() => connectionFixtures.filter(connection => connection.type === form.typeKey).map(connection => ({ label: connection.name, value: connection.id })))
const broadcastPreflight = ref<PreflightState | null>(null); let itemsTimer: ReturnType<typeof setTimeout> | undefined; let storageTimer: ReturnType<typeof setTimeout> | undefined
function clearTimers() { clearTimeout(itemsTimer); clearTimeout(storageTimer) }
function runAnalysis() {
  clearTimers(); broadcastPreflight.value = null; const type = selectedType.value; const total = stash.value?.itemCount ?? 0
  if (!type || total === 0) return
  itemsTimer = setTimeout(() => {
    if (selectedType.value?.key !== type.key) return
    const operations: PreflightOperation[] = type.configComponent === 'media-server' ? [{ key: 'hardlink', label: 'Hardlink', itemCount: total, storageLabel: 'no additional space', icon: 'i-lucide-link' }] : [{ key: 'plugin', label: 'Plugin output', itemCount: total, storageLabel: 'managed by plugin', icon: 'i-lucide-box' }]
    broadcastPreflight.value = { status: 'analyzing', plan: { itemCountLabel: `${total.toLocaleString()} items`, operations, storage: { kind: 'calculating' } } }
    storageTimer = setTimeout(() => { if (selectedType.value?.key === type.key) broadcastPreflight.value = { status: 'ready', plan: { itemCountLabel: `${total.toLocaleString()} items`, operations, storage: { kind: 'none' } } } }, 400)
  }, 150)
}
watch(() => form.typeKey, runAnalysis, { immediate: true }); onUnmounted(clearTimers)
function validate(state: Partial<typeof form>): FormError[] {
  if (!state.typeKey) return [{ name: 'typeKey', message: 'Choose a broadcast type to continue.' }]
  if (selectedType.value?.configComponent === 'plugin') return (selectedType.value.controls ?? []).filter(control => control.required && !String(state.settings?.[control.key] ?? '').trim()).map(control => ({ name: `settings.${control.key}`, message: `${control.label} is required.` }))
  const errors: FormError[] = []; if (!state.connectionId) errors.push({ name: 'connectionId', message: 'Choose a connection.' }); if (!state.libraryName?.trim()) errors.push({ name: 'libraryName', message: 'Enter the library name.' }); return errors
}
function settingValue(key: string): string { return String(form.settings[key] ?? '') }
function setSetting(key: string, value: unknown): void { form.settings[key] = typeof value === 'boolean' ? value : String(value ?? '') }
function onSubmit(event: FormSubmitEvent<typeof form>) {
  if (!stash.value || !selectedType.value) return
  const plugin = selectedType.value.configComponent === 'plugin'; const title = String(event.data.settings.title ?? stash.value.name).trim()
  const broadcast: BroadcastFixture = { id: `${stash.value.id}-broadcast-${broadcastFixtures.length + 1}-${Date.now()}`, stashId: stash.value.id, kind: selectedType.value.key, name: plugin ? `${title} · ${selectedType.value.label}` : `${stash.value.name} · ${selectedType.value.label}`, formLabel: selectedType.value.label, status: 'active', buildState: 'stale', lastRebuild: 'never', lastRebuildAt: new Date().toISOString(), itemsPublished: 0, itemsTotal: stash.value.itemCount }
  broadcastFixtures.push(broadcast); router.push(`/stashes/${stash.value.id}`)
}
</script>

<template>
  <main v-if="stash" class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink :to="`/stashes/${stash.id}`" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed">{{ stash.name }}</RouterLink>
    <header class="space-y-1.5"><h1 class="text-2xl font-semibold text-highlighted">New broadcast</h1><p class="text-sm text-muted">Choose an output for <span class="font-mono text-toned">{{ stash.name }}</span>.</p></header>
    <UForm :state="form" :validate="validate" class="space-y-8" @submit="onSubmit">
      <UFormField name="typeKey"><URadioGroup v-model="form.typeKey" :items="typeItems" variant="card" size="lg" /></UFormField>
      <template v-if="selectedType">
        <div v-if="selectedType.configComponent === 'plugin'" class="space-y-4">
          <UFormField v-for="control in selectedType.controls" :key="control.key" :name="`settings.${control.key}`" :label="control.label"><UTextarea v-if="control.type === 'textarea'" :model-value="settingValue(control.key)" class="w-full" @update:model-value="setSetting(control.key, $event)" /><USelect v-else-if="control.type === 'select'" :model-value="settingValue(control.key)" :items="control.options ?? []" class="w-full" @update:model-value="setSetting(control.key, $event)" /><USwitch v-else-if="control.type === 'boolean'" :model-value="form.settings[control.key] === true" @update:model-value="setSetting(control.key, $event)" /><UInput v-else :model-value="settingValue(control.key)" class="w-full" @update:model-value="setSetting(control.key, $event)" /></UFormField>
        </div>
        <div v-else class="space-y-5">
          <UFormField name="connectionId" :label="`${selectedType.label} connection`"><USelect v-model="form.connectionId" :items="connectionOptions" value-key="value" class="w-full" /></UFormField>
          <UFormField name="libraryName" label="Library"><UInput v-model="form.libraryName" class="w-full" /></UFormField>
        </div>
      </template>
      <PreflightSummary v-if="broadcastPreflight" :state="broadcastPreflight" />
      <div class="flex gap-2"><UButton label="Create broadcast" type="submit" size="lg" :disabled="!form.typeKey" /><UButton label="Cancel" :to="`/stashes/${stash.id}`" variant="ghost" color="neutral" size="lg" /></div>
    </UForm>
  </main>
  <main v-else class="mx-auto max-w-2xl px-4 py-8 sm:px-8"><p class="text-sm text-muted">Stash not found.</p></main>
</template>
