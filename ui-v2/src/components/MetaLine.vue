<script setup lang="ts">
/**
 * A compact status/metadata rail: a leading status dot+label (optional),
 * followed by discrete semantic groups. Each group is one atomic unit that
 * never splits mid-phrase — wrapping only ever happens BETWEEN groups.
 * Join closely related concepts inside one group with "·" (e.g. "Podcast ·
 * Audio"); groups themselves are separated by spacing only, never a dot.
 */
export interface MetaLineItem {
  text: string
  /** ISO datetime — renders `text` inside a semantic <time> with an absolute-time tooltip. */
  datetime?: string
}

withDefaults(defineProps<{
  status?: { label: string, dot: string }
  items?: MetaLineItem[]
}>(), {
  status: undefined,
  items: () => []
})

function absoluteTime(iso: string) {
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
    <span v-if="status" class="inline-flex shrink-0 items-center gap-1">
      <span class="size-1 rounded-full" :class="status.dot" />
      <span class="font-mono text-xs uppercase text-dimmed">{{ status.label }}</span>
    </span>

    <template v-for="(item, i) in items" :key="i">
      <UTooltip v-if="item.datetime" :text="absoluteTime(item.datetime)">
        <time :datetime="item.datetime" class="whitespace-nowrap font-mono text-xs text-dimmed">{{ item.text }}</time>
      </UTooltip>
      <span v-else class="whitespace-nowrap font-mono text-xs text-dimmed">{{ item.text }}</span>
    </template>

    <!-- Escape hatch for a group that isn't plain text (e.g. an icon+count
         pairing) — still one atomic group, still lands in the same wrap flow. -->
    <slot />
  </div>
</template>
