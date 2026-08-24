<script setup lang="ts">
defineProps<{
  modelValue: boolean
  name: string
  label: string
  description?: string
  required?: boolean
  error?: string
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <UFormField :name="name" :error="error" class="space-y-1.5">
    <div class="space-y-1">
      <div class="flex items-center justify-between gap-4">
        <span :id="`${name}-label`" class="text-sm font-medium text-highlighted">
          {{ label }}<span v-if="required" class="ml-1 text-primary" aria-hidden="true">*</span>
        </span>
        <USwitch
          :id="name"
          :model-value="modelValue"
          :disabled="disabled"
          :aria-labelledby="`${name}-label`"
          :aria-describedby="description ? `${name}-description` : undefined"
          @update:model-value="emit('update:modelValue', $event)"
        />
      </div>
      <p v-if="description" :id="`${name}-description`" class="text-sm text-muted">{{ description }}</p>
    </div>
  </UFormField>
</template>
