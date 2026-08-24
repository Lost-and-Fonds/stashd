<script setup lang="ts">
defineProps<{
  modelValue?: string
  disabled?: boolean
  url?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <UInput
    :model-value="modelValue"
    :type="url ? 'url' : 'text'"
    :disabled="disabled"
    :placeholder="url ? 'https://…' : undefined"
    class="w-full font-mono disabled:cursor-not-allowed disabled:border-default disabled:bg-muted disabled:text-dimmed disabled:opacity-100"
    @update:model-value="emit('update:modelValue', String($event ?? ''))"
  />
</template>
