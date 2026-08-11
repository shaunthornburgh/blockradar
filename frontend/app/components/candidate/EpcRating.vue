<script setup lang="ts">
const props = defineProps<{ rating: string | null | undefined }>()

/**
 * The familiar EPC colour ramp, A (dark green) through G (red). Kept as
 * explicit classes so Tailwind can see them at build time.
 */
const CLASSES: Record<string, string> = {
  A: 'bg-green-700 text-white',
  B: 'bg-green-600 text-white',
  C: 'bg-lime-500 text-black',
  D: 'bg-yellow-400 text-black',
  E: 'bg-orange-400 text-black',
  F: 'bg-orange-600 text-white',
  G: 'bg-red-600 text-white'
}

const band = computed(() => props.rating?.toUpperCase() ?? null)
const classes = computed(() => (band.value ? CLASSES[band.value] : null))
</script>

<template>
  <span
    v-if="band && classes"
    class="inline-flex items-center justify-center size-6 rounded font-bold text-xs shrink-0"
    :class="classes"
    :title="`EPC band ${band}`"
  >
    {{ band }}
  </span>
  <span
    v-else
    class="inline-flex items-center justify-center size-6 rounded bg-elevated text-dimmed text-xs shrink-0"
    title="No EPC band"
  >
    —
  </span>
</template>
