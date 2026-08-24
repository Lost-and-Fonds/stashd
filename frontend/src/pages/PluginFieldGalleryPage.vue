<script setup lang="ts">
import { computed, reactive } from 'vue'
import PluginField from '../components/plugin/PluginField.vue'
import { pluginFieldFixtures } from '../fixtures/pluginFields'
import type { PluginFieldValue } from '../types/plugin-ui'

const values = reactive<Record<string, PluginFieldValue | undefined>>(
  Object.fromEntries(pluginFieldFixtures.map(({ field, value }) => [field.key, value]))
)

const sections = computed(() => ['General', 'Publishing', 'Media'].map(name => ({
  name,
  fixtures: pluginFieldFixtures.filter(fixture => fixture.section === name)
})))
</script>

<template>
  <main class="mx-auto max-w-3xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink to="/design" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Design
    </RouterLink>

    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">Plugin fields</h1>
      <p class="text-sm text-muted">Local fixtures for the shared controls that plugin configuration will use.</p>
    </header>

    <UCard :ui="{ body: 'p-4 sm:p-6' }">
      <form class="space-y-8" @submit.prevent>
        <section v-for="(section, index) in sections" :key="section.name" class="space-y-4" :class="index > 0 && 'border-t border-default pt-7'">
          <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">{{ section.name }}</h2>
          <div class="space-y-4">
            <PluginField
              v-for="fixture in section.fixtures"
              :key="fixture.field.key"
              v-model="values[fixture.field.key]"
              :field="fixture.field"
              :disabled="fixture.disabled"
              :error="fixture.error"
              :url="fixture.url"
            />
          </div>
        </section>
      </form>
    </UCard>
  </main>
</template>
