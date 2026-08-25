<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { currentUser, type AuthUser } from '../api/auth'
import { fetchPluginCredentials, replacePluginCredential, type PluginCredentialPluginResource } from '../api/pluginCredentials'
import { createApiToken, fetchApiTokens, revokeApiToken, type ApiTokenResource } from '../api/tokens'

const user = ref<AuthUser>()
const tokens = ref<ApiTokenResource[]>([])
const pluginCredentials = ref<PluginCredentialPluginResource[]>([])
const loading = ref(true)
const creating = ref(false)
const savingCredential = ref<string>()
const error = ref<string>()
const notice = ref<string>()
const tokenName = ref('')
const createdToken = ref<string>()
const credentialValues = ref<Record<string, string>>({})

function formatDate(value?: string | null) {
  return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'Never'
}

async function load() {
  loading.value = true
  error.value = undefined

  try {
    const [current, resources, credentials] = await Promise.all([currentUser(), fetchApiTokens(), fetchPluginCredentials()])
    user.value = current ?? undefined
    tokens.value = resources
    pluginCredentials.value = credentials
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not load Configure.'
  } finally {
    loading.value = false
  }
}

function credentialFormKey(pluginKey: string, credentialKey: string) {
  return `${pluginKey}:${credentialKey}`
}

async function savePluginCredential(pluginKey: string, credentialKey: string) {
  const formKey = credentialFormKey(pluginKey, credentialKey)
  const value = credentialValues.value[formKey]
  if (!value?.trim()) return

  savingCredential.value = formKey
  error.value = undefined
  notice.value = undefined

  try {
    const updated = await replacePluginCredential(pluginKey, credentialKey, value)
    pluginCredentials.value = pluginCredentials.value.map(plugin => plugin.key === pluginKey
      ? { ...plugin, credentials: plugin.credentials.map(credential => credential.key === credentialKey ? updated : credential) }
      : plugin)
    credentialValues.value = { ...credentialValues.value, [formKey]: '' }
    notice.value = `${updated.label} configured.`
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not save credential.'
  } finally {
    savingCredential.value = undefined
  }
}

async function createToken() {
  const name = tokenName.value.trim()
  if (!name) return

  creating.value = true
  error.value = undefined
  notice.value = undefined

  try {
    const token = await createApiToken(name)
    createdToken.value = token.token
    tokenName.value = ''
    tokens.value = await fetchApiTokens()
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not create API token.'
  } finally {
    creating.value = false
  }
}

async function revokeToken(token: ApiTokenResource) {
  if (!window.confirm(`Revoke ${token.name}? Anything using it will stop working immediately.`)) return

  error.value = undefined
  notice.value = undefined

  try {
    await revokeApiToken(token.id)
    tokens.value = tokens.value.filter(candidate => candidate.id !== token.id)
    notice.value = `${token.name} revoked.`
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not revoke API token.'
  }
}

async function copyCreatedToken() {
  if (!createdToken.value) return

  try {
    await navigator.clipboard.writeText(createdToken.value)
    notice.value = 'API token copied.'
  } catch {
    notice.value = 'Copy the API token manually before leaving this page.'
  }
}

onMounted(load)
</script>

<template>
  <main class="mx-auto max-w-3xl space-y-8 px-4 py-8 sm:px-8">
    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">Configure</h1>
      <p class="text-sm text-muted">Connections and API access for this Stashd instance.</p>
    </header>

    <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Configuration error" :description="error" />
    <UAlert v-if="notice" color="success" variant="subtle" icon="i-lucide-circle-check" :description="notice" />

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted"><UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />Loading Configure…</div>

    <template v-else>
      <section class="space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-medium text-highlighted">Connections</h2>
            <p class="mt-1 text-sm text-muted">Set up media-server integrations. Their credentials stay with the Connection and are never returned.</p>
          </div>
          <UButton label="Manage Connections" icon="i-lucide-plug" to="/settings/connections" variant="subtle" color="neutral" />
        </div>
      </section>

      <section v-if="pluginCredentials.length" class="space-y-4 border-t border-default pt-6">
        <div>
          <h2 class="text-base font-medium text-highlighted">Plugin credentials</h2>
          <p class="mt-1 text-sm text-muted">Credentials support the integrations that need them. Existing values are never shown.</p>
        </div>

        <div v-for="plugin in pluginCredentials" :key="plugin.key" class="space-y-3">
          <h3 class="font-mono text-sm text-toned">{{ plugin.label }}</h3>
          <form v-for="credential in plugin.credentials" :key="credential.key" class="rounded-md border border-default bg-muted p-4" @submit.prevent="savePluginCredential(plugin.key, credential.key)">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h4 class="text-sm font-medium text-highlighted">{{ credential.label }}</h4>
                <p v-if="credential.description" class="mt-1 text-sm text-muted">{{ credential.description }}</p>
                <p class="mt-1 text-xs" :class="credential.configured ? 'text-success' : 'text-muted'">{{ credential.configured ? 'Configured' : 'Not configured' }}</p>
              </div>
            </div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
              <UInput v-model="credentialValues[credentialFormKey(plugin.key, credential.key)]" type="password" autocomplete="new-password" :placeholder="credential.configured ? 'Enter a replacement value' : 'Enter a value'" class="flex-1" />
              <UButton type="submit" :label="credential.configured ? 'Replace' : 'Save'" :loading="savingCredential === credentialFormKey(plugin.key, credential.key)" :disabled="!credentialValues[credentialFormKey(plugin.key, credential.key)]?.trim()" />
            </div>
          </form>
        </div>
      </section>

      <section class="space-y-3 border-t border-default pt-6">
        <div>
          <h2 class="text-base font-medium text-highlighted">API access</h2>
          <p class="mt-1 text-sm text-muted">Create tokens for scripts and integrations. A new token is shown once only.</p>
        </div>

        <UAlert v-if="createdToken" color="warning" variant="subtle" icon="i-lucide-key-round" title="Copy this token now">
          <template #description>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <code class="min-w-0 flex-1 break-all rounded-md bg-elevated px-3 py-2 font-mono text-xs text-highlighted">{{ createdToken }}</code>
              <UButton label="Copy" size="sm" variant="subtle" color="neutral" @click="copyCreatedToken" />
            </div>
          </template>
        </UAlert>

        <form class="flex flex-col gap-2 sm:flex-row" @submit.prevent="createToken">
          <UInput v-model="tokenName" placeholder="Token name" class="flex-1" required />
          <UButton type="submit" label="Create token" :loading="creating" :disabled="!tokenName.trim()" />
        </form>

        <div v-if="tokens.length" class="divide-y divide-default/60 overflow-hidden rounded-md border border-default bg-muted">
          <div v-for="token in tokens" :key="token.id" class="flex flex-wrap items-center justify-between gap-3 p-3">
            <div class="min-w-0 space-y-1">
              <p class="text-sm text-highlighted">{{ token.name }}</p>
              <p class="font-mono text-xs text-dimmed">{{ token.token_preview }} · last used {{ formatDate(token.last_used_at) }}</p>
            </div>
            <UButton label="Revoke" size="sm" variant="ghost" color="error" @click="revokeToken(token)" />
          </div>
        </div>
        <p v-else class="text-sm text-muted">No API tokens created yet.</p>
      </section>

      <section v-if="user" class="border-t border-default pt-6">
        <h2 class="text-base font-medium text-highlighted">Account</h2>
        <p class="mt-1 text-sm text-muted"><span class="font-mono text-toned">{{ user.username }}</span> · {{ user.role }}</p>
      </section>
    </template>
  </main>
</template>
