<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { createConnection, deleteConnection, fetchConnections, runConnectionOperation, updateConnection } from '../api/connections'
import { fetchBroadcastPlugins } from '../api/broadcasts'
import type { ConnectionApiResource } from '../types/connection'

const connections = ref<ConnectionApiResource[]>([])
const pluginOptions = ref<{ label: string, value: string }[]>([])
const loading = ref(true)
const saving = ref(false)
const testing = ref<string>()
const error = ref<string>()
const notice = ref<string>()
const editingId = ref<string>()
const form = reactive({ plugin_key: '', name: '', endpoint: '', token: '' })

const editing = computed(() => connections.value.find(connection => connection.id === editingId.value))

function resetForm() {
  editingId.value = undefined
  Object.assign(form, { plugin_key: pluginOptions.value[0]?.value ?? '', name: '', endpoint: '', token: '' })
}

function edit(connection: ConnectionApiResource) {
  editingId.value = connection.id
  Object.assign(form, { plugin_key: connection.plugin_key, name: connection.name, endpoint: connection.endpoint, token: '' })
  error.value = undefined
  notice.value = undefined
}

async function load() {
  loading.value = true
  error.value = undefined

  try {
    const [resources, plugins] = await Promise.all([fetchConnections(), fetchBroadcastPlugins()])
    connections.value = resources
    pluginOptions.value = plugins
      .filter(plugin => plugin.connection_setting_key)
      .map(plugin => ({ label: plugin.label, value: plugin.key }))
    if (!editingId.value) resetForm()
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not load Connections.'
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!form.plugin_key || !form.name.trim() || !form.endpoint.trim()) return
  saving.value = true
  error.value = undefined
  notice.value = undefined

  try {
    const payload = { ...form, ...(form.token.trim() ? { token: form.token } : {}) }
    const connection = editing.value
      ? await updateConnection(editing.value.id, payload)
      : await createConnection(payload)
    connections.value = editing.value
      ? connections.value.map(candidate => candidate.id === connection.id ? connection : candidate)
      : [...connections.value, connection]
    notice.value = editing.value ? 'Connection updated.' : 'Connection created.'
    resetForm()
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not save Connection.'
  } finally {
    saving.value = false
  }
}

async function test(connection: ConnectionApiResource) {
  testing.value = connection.id
  error.value = undefined
  notice.value = undefined

  try {
    const result = await runConnectionOperation(connection.id, 'test_connection')
    const message = result.values?.find(value => value.key === 'message')?.value
    notice.value = typeof message === 'string' && message !== '' ? message : `${connection.name} is reachable.`
    await load()
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Connection test failed.'
  } finally {
    testing.value = undefined
  }
}

async function remove(connection: ConnectionApiResource) {
  if (! window.confirm(`Delete ${connection.name}?`)) return

  try {
    await deleteConnection(connection.id)
    connections.value = connections.value.filter(candidate => candidate.id !== connection.id)
    if (editingId.value === connection.id) resetForm()
    notice.value = 'Connection deleted.'
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not delete Connection.'
  }
}

onMounted(load)
</script>

<template>
  <main class="mx-auto max-w-5xl space-y-8 px-4 py-8 sm:px-8">
    <header class="space-y-1.5">
      <RouterLink to="/settings" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
        <UIcon name="i-lucide-arrow-left" class="size-3.5" />
        Configure
      </RouterLink>
      <h1 class="text-2xl font-semibold text-highlighted">Connections</h1>
      <p class="text-sm text-muted">Configure the media servers used by Broadcasts.</p>
    </header>

    <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Connection error" :description="error" />
    <UAlert v-if="notice" color="success" variant="subtle" icon="i-lucide-circle-check" :description="notice" />

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted"><UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />Loading Connections…</div>

    <template v-else>
      <UCard :ui="{ body: 'p-4 sm:p-6' }">
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
          <UFormField label="Provider" required>
            <USelect v-model="form.plugin_key" :items="pluginOptions" value-key="value" label-key="label" :disabled="Boolean(editingId)" />
          </UFormField>
          <UFormField label="Name" required><UInput v-model="form.name" placeholder="Home media server" /></UFormField>
          <UFormField label="Server URL" required><UInput v-model="form.endpoint" type="url" placeholder="https://media.example" /></UFormField>
          <UFormField label="API token" :description="editingId ? 'Leave blank to keep the existing token.' : 'Stored encrypted and never returned.'">
            <UInput v-model="form.token" type="password" autocomplete="new-password" />
          </UFormField>
          <div class="flex gap-2 sm:col-span-2">
            <UButton type="submit" :loading="saving" :disabled="pluginOptions.length === 0">{{ editingId ? 'Save changes' : 'Add Connection' }}</UButton>
            <UButton v-if="editingId" type="button" label="Cancel" variant="ghost" color="neutral" @click="resetForm" />
          </div>
        </form>
      </UCard>

      <section class="space-y-3">
        <h2 class="text-sm font-medium text-muted">Configured Connections</h2>
        <UCard v-for="connection in connections" :key="connection.id" :ui="{ body: 'p-4' }">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
              <h3 class="font-medium text-highlighted">{{ connection.name }}</h3>
              <p class="font-mono text-xs text-muted">{{ connection.plugin_key }} · {{ connection.endpoint }}</p>
              <p class="text-xs text-muted">{{ connection.state }}<span v-if="connection.last_error"> · {{ connection.last_error }}</span></p>
            </div>
            <div class="flex gap-2">
              <UButton label="Test" size="sm" variant="soft" :loading="testing === connection.id" @click="test(connection)" />
              <UButton label="Configure" size="sm" variant="ghost" color="neutral" @click="edit(connection)" />
              <UButton label="Delete" size="sm" variant="ghost" color="error" @click="remove(connection)" />
            </div>
          </div>
        </UCard>
        <p v-if="connections.length === 0" class="rounded-lg border border-dashed border-default p-6 text-center text-sm text-muted">No Connections configured yet.</p>
      </section>
    </template>
  </main>
</template>
