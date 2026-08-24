<script setup lang="ts">
defineProps<{
  modelValue?: string
  choices: string[]
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <USelect
    :model-value="modelValue"
    :items="choices"
    :disabled="disabled"
    class="w-full font-mono disabled:cursor-not-allowed disabled:border-default disabled:bg-muted disabled:text-dimmed disabled:opacity-100"
    @update:model-value="emit('update:modelValue', String($event ?? ''))"
  />
</template>
