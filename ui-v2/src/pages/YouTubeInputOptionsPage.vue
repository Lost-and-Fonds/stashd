<script setup lang="ts">
import { reactive } from 'vue'
import { inputOptionValues, normalizeInputOptions } from '../adapters/normalizeInputOptions'
import PluginField from '../components/plugin/PluginField.vue'
import { youtubeInputOptions } from '../fixtures/youtubeInputOptions'
import type { PluginFieldValue } from '../types/plugin-ui'

const { fields } = normalizeInputOptions(youtubeInputOptions.input_options)
const values = reactive<Record<string, PluginFieldValue | undefined>>(
  inputOptionValues(fields, youtubeInputOptions.options?.provider)
)
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink to="/design" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Design
    </RouterLink>

    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">YouTube input options</h1>
      <p class="text-sm text-muted">A real input-option declaration normalized into the shared plugin-field renderer.</p>
    </header>

    <UAlert
      color="neutral"
      variant="subtle"
      icon="i-lucide-brackets"
      title="API-shaped declaration fixture"
      description="Configured provider values win; undeclared values fall back to the declaration default. Live input loading belongs to the later Input integration slice."
    />

    <UCard :ui="{ body: 'p-4 sm:p-6' }">
      <form class="space-y-5" @submit.prevent>
        <PluginField
          v-for="field in fields"
          :key="field.key"
          v-model="values[field.key]"
          :field="field"
        />
      </form>
    </UCard>
  </main>
</template>
