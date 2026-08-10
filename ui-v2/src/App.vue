<script setup lang="ts">
const primaryLinks = [
  { label: 'Status', icon: 'i-lucide-activity', to: '/status' },
  { label: 'Stashes', icon: 'i-lucide-inbox', to: '/stashes' },
  { label: 'Vault', icon: 'i-lucide-archive', to: '/vault' }
]

const secondaryLinks = [
  { label: 'Connections', icon: 'i-lucide-plug', to: '/connections' },
  { label: 'Secrets', icon: 'i-lucide-key-round', to: '/secrets' },
  { label: 'Settings', icon: 'i-lucide-settings', to: '/settings' }
]

const designLink = { label: 'Design', icon: 'i-lucide-swatch-book', to: '/design' }

const mobileItems = [primaryLinks, secondaryLinks, [designLink]]

const navUi = { linkLabel: 'font-mono' }
const dropdownUi = { itemLabel: 'font-mono' }
</script>

<template>
  <UApp>
    <div class="flex min-h-svh flex-col">
      <header class="flex h-14 shrink-0 items-center gap-1 border-b border-default px-4 sm:px-6">
        <RouterLink to="/" class="mr-2 shrink-0 font-mono text-lg text-highlighted">
          stashd<span class="text-primary">_</span>
        </RouterLink>

        <UNavigationMenu :items="primaryLinks" :ui="navUi" class="hidden md:flex" />

        <div class="ml-auto hidden items-center gap-2 md:flex">
          <UDropdownMenu :items="secondaryLinks" :ui="dropdownUi">
            <UButton label="Configure" icon="i-lucide-sliders-horizontal" trailing-icon="i-lucide-chevron-down" variant="ghost" color="neutral" size="sm" class="font-mono" />
          </UDropdownMenu>
          <RouterLink
            to="/design"
            class="flex items-center gap-1 rounded-md px-2 py-1 font-mono text-xs text-dimmed transition-colors hover:text-muted"
          >
            <UIcon name="i-lucide-swatch-book" class="size-3.5" />
            design
          </RouterLink>
        </div>

        <UDrawer direction="left" :handle="false" class="ml-auto md:hidden">
          <UButton icon="i-lucide-menu" variant="ghost" color="neutral" aria-label="Open navigation" />
          <template #body>
            <div class="flex w-64 flex-col gap-6">
              <p class="font-mono text-lg text-highlighted">
                stashd<span class="text-primary">_</span>
              </p>
              <UNavigationMenu :items="mobileItems" :ui="navUi" orientation="vertical" />
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
