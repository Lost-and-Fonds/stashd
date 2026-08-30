<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import { logout } from './api/auth'
import { stopLiveUpdates } from './live/mercure'

const route = useRoute()
const router = useRouter()

async function signOut(): Promise<void> {
  try {
    await logout()
  } finally {
    stopLiveUpdates()
    await router.push({ name: 'login' })
  }
}

const primaryLinks = [
  { label: 'Status', icon: 'i-lucide-activity', to: '/status' },
  { label: 'Stashes', icon: 'i-lucide-inbox', to: '/stashes' },
  { label: 'Vault', icon: 'i-lucide-archive', to: '/vault' }
]

const mobileItems = [primaryLinks, [{ label: 'Configure', icon: 'i-lucide-sliders-horizontal', to: '/settings' }]]

</script>

<template>
  <UApp>
    <div class="flex min-h-svh flex-col">
      <header v-if="route.path !== '/login'" class="flex h-14 shrink-0 items-center gap-1 border-b border-default px-4 sm:px-6">
        <RouterLink to="/" class="mr-2 shrink-0 font-mono text-lg text-highlighted">
          stashd<span class="text-primary">_</span>
        </RouterLink>

        <nav class="hidden items-center gap-1 md:flex" aria-label="Primary navigation">
          <RouterLink v-for="item in primaryLinks" :key="item.to" :to="item.to" class="rounded-md px-2.5 py-1.5 font-mono text-sm text-muted transition-colors hover:bg-elevated/50 hover:text-highlighted">
            {{ item.label }}
          </RouterLink>
        </nav>

        <div class="ml-auto hidden items-center gap-2 md:flex">
          <UButton label="Configure" icon="i-lucide-sliders-horizontal" to="/settings" variant="ghost" color="neutral" size="sm" class="font-mono" />
          <UButton label="Sign out" variant="ghost" color="neutral" size="sm" class="font-mono" @click="signOut" />
        </div>

        <UDrawer direction="left" :handle="false" class="ml-auto md:hidden">
          <UButton icon="i-lucide-menu" variant="ghost" color="neutral" aria-label="Open navigation" />
          <template #body>
            <div class="flex w-64 flex-col gap-6">
              <p class="font-mono text-lg text-highlighted">
                stashd<span class="text-primary">_</span>
              </p>
              <nav class="flex flex-col gap-1" aria-label="Primary navigation">
                <RouterLink v-for="item in mobileItems.flat()" :key="item.to" :to="item.to" class="rounded-md px-2.5 py-2 font-mono text-sm text-muted transition-colors hover:bg-elevated/50 hover:text-highlighted">
                  {{ item.label }}
                </RouterLink>
              </nav>
            </div>
          </template>
        </UDrawer>
      </header>

      <main class="min-h-0 flex-1 overflow-y-auto">
        <RouterView />
      </main>
    </div>
  </UApp>
</template>
