<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { authState } from '../api/auth'
import { startLiveUpdates } from '../live/mercure'

const route = useRoute()
const router = useRouter()
const username = ref('')
const password = ref('')
const error = ref('')
const loading = ref(true)
const setupRequired = ref<boolean | null>(null)

onMounted(async () => {
  try {
    setupRequired.value = (await authState()) === 'setup-required'
  } catch {
    setupRequired.value = false
  } finally {
    loading.value = false
  }
})

async function submit(): Promise<void> {
  error.value = ''
  loading.value = true

  try {
    const response = await fetch(setupRequired.value ? '/api/v1/auth/setup' : '/api/v1/auth/login', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ username: username.value, password: password.value })
    })
    const payload = await response.json().catch(() => ({})) as { error?: { code?: string; message?: string } }

    if (response.ok) {
      startLiveUpdates()
      const returnTo = typeof route.query.return_to === 'string' && route.query.return_to.startsWith('/')
        ? route.query.return_to
        : '/stashes'
      await router.push(returnTo)
      return
    }

    if (payload.error?.code === 'setup_required') {
      setupRequired.value = true
    }
    error.value = payload.error?.message ?? 'Could not authenticate.'
  } catch {
    error.value = 'Could not reach the server.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="flex min-h-svh items-center justify-center px-5">
    <div class="w-full max-w-sm">
      <div class="mb-6 text-center">
        <div class="font-mono text-2xl font-semibold tracking-tight">stashd<span class="text-primary">_</span></div>
        <p class="mt-2 text-[13px] text-muted">{{ setupRequired ? 'Create the admin account.' : 'Because the internet forgets.' }}</p>
      </div>

      <form class="space-y-3 rounded-lg border border-default bg-elevated/60 p-5" @submit.prevent="submit">
        <template v-if="setupRequired !== null">
          <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" :description="error" />
          <UFormField label="Username" required>
            <UInput v-model="username" autocomplete="username" required class="w-full" />
          </UFormField>
          <UFormField label="Password" required>
            <UInput v-model="password" type="password" :autocomplete="setupRequired ? 'new-password' : 'current-password'" required class="w-full" />
          </UFormField>
          <UButton block type="submit" :loading="loading" :label="setupRequired ? 'Create admin' : 'Sign in'" />
        </template>
        <UIcon v-else name="i-lucide-loader-circle" class="mx-auto size-5 animate-spin" aria-label="Checking account setup" />
      </form>
    </div>
  </main>
</template>
