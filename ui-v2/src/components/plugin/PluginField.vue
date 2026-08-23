<script setup lang="ts">
import type { PluginField, PluginFieldValue } from '../../types/plugin-ui'
import PluginFieldBoolean from './PluginFieldBoolean.vue'
import PluginFieldNumber from './PluginFieldNumber.vue'
import PluginFieldSelect from './PluginFieldSelect.vue'
import PluginFieldText from './PluginFieldText.vue'

const props = defineProps<{
  field: PluginField
  modelValue?: PluginFieldValue
  disabled?: boolean
  error?: string
  url?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: PluginFieldValue | undefined] }>()

function update(value: PluginFieldValue | undefined) {
  emit('update:modelValue', value)
}
</script>

<template>
  <UFormField
    v-if="field.type !== 'boolean'"
    :name="field.key"
    :label="field.label"
    :description="field.description"
    :required="field.required"
    :error="error"
  >
    <PluginFieldNumber
      v-if="field.type === 'number'"
      :model-value="typeof modelValue === 'number' ? modelValue : undefined"
      :disabled="disabled"
      @update:model-value="update"
    />
    <PluginFieldSelect
      v-else-if="field.type === 'select'"
      :model-value="typeof modelValue === 'string' ? modelValue : undefined"
      :choices="field.choices ?? []"
      :disabled="disabled"
      @update:model-value="update"
    />
    <PluginFieldText
      v-else-if="field.type === 'text'"
      :model-value="typeof modelValue === 'string' ? modelValue : undefined"
      :disabled="disabled"
      :url="url"
      @update:model-value="update"
    />
  </UFormField>
  <PluginFieldBoolean
    v-else
    :name="field.key"
    :label="field.label"
    :description="field.description"
    :required="field.required"
    :error="error"
    :model-value="Boolean(modelValue)"
    :disabled="disabled"
    @update:model-value="update"
  />
</template>
