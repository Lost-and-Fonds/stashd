<script setup lang="ts">
defineProps<{
  modelValue?: number
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: number | undefined] }>()

function update(value: string | number | undefined) {
  emit('update:modelValue', value === '' || value === undefined ? undefined : Number(value))
}
</script>

<template>
  <UInput
    :model-value="modelValue"
    type="number"
    :disabled="disabled"
    class="plugin-field-number w-full font-mono disabled:cursor-not-allowed disabled:border-default disabled:bg-muted disabled:text-dimmed disabled:opacity-100"
    @update:model-value="update($event)"
  />
</template>
