<script setup lang="ts">
import type { ScoreBreakdown } from '~/types'

const props = defineProps<{
  breakdown: ScoreBreakdown | Record<string, never>
  score: number
  scoredAt: string | null
}>()

/** Readable names for the component keys stored in score_breakdown. */
const LABELS: Record<string, string> = {
  area_yield: 'Area yield',
  estimated_units: 'Estimated units',
  title_split_upside: 'Title split upside',
  ownership_duration: 'Ownership duration',
  epc_refurb_potential: 'EPC refurb potential',
  filing_distress: 'Filing distress',
  charges_pressure: 'Charges pressure'
}

const hasBreakdown = computed(() =>
  Boolean((props.breakdown as ScoreBreakdown)?.components)
)

const full = computed(() => props.breakdown as ScoreBreakdown)

const components = computed(() => {
  if (!hasBreakdown.value) return []

  return Object.entries(full.value.components)
    .map(([key, component]) => ({
      key,
      label: LABELS[key] ?? key.replace(/_/g, ' '),
      ...component
    }))
    // Available components first, then by the points they contributed.
    .sort((a, b) => Number(b.available) - Number(a.available) || b.points - a.points)
})

const available = computed(() => components.value.filter(c => c.available))
const unavailable = computed(() => components.value.filter(c => !c.available))

/** How much of the model had data, as a percentage of total weight. */
const coverage = computed(() => {
  if (!hasBreakdown.value || !full.value.weight_total) return 0

  return Math.round((full.value.weight_available / full.value.weight_total) * 100)
})
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="font-semibold">
            Score breakdown
          </h2>
          <p class="text-xs text-muted mt-0.5">
            <template v-if="hasBreakdown">
              Weighted average over the {{ full.weight_available }} of
              {{ full.weight_total }} points that had data
            </template>
            <template v-else>
              No breakdown stored
            </template>
          </p>
        </div>

        <div class="text-right shrink-0">
          <UBadge
            :color="scoreColor(score)"
            variant="subtle"
            size="lg"
            class="tabular-nums"
          >
            {{ score }}
          </UBadge>
          <p class="text-xs text-dimmed mt-1">
            {{ scoredAt ? formatDate(scoredAt) : 'never scored' }}
          </p>
        </div>
      </div>
    </template>

    <UAlert
      v-if="!hasBreakdown"
      color="neutral"
      variant="subtle"
      icon="i-lucide-info"
      description="This candidate was scored before breakdowns were recorded. Run candidates:rescore to populate it."
    />

    <div
      v-else
      class="space-y-4"
    >
      <div>
        <div class="flex items-center justify-between text-xs text-muted mb-1.5">
          <span>Model coverage</span>
          <span class="tabular-nums">{{ coverage }}%</span>
        </div>
        <UProgress
          :model-value="coverage"
          :color="coverage >= 90 ? 'success' : coverage >= 60 ? 'warning' : 'neutral'"
          size="sm"
        />
      </div>

      <USeparator />

      <div class="space-y-3">
        <div
          v-for="component in available"
          :key="component.key"
        >
          <div class="flex items-baseline justify-between gap-3 text-sm">
            <span class="font-medium">{{ component.label }}</span>
            <span class="tabular-nums text-muted shrink-0">
              {{ component.points }} / {{ component.weight }}
            </span>
          </div>

          <UProgress
            :model-value="(component.value ?? 0) * 100"
            :color="(component.value ?? 0) >= 0.66 ? 'success' : (component.value ?? 0) >= 0.33 ? 'warning' : 'neutral'"
            size="xs"
            class="my-1.5"
          />

          <p class="text-xs text-muted">
            {{ component.note }}
          </p>

          <div
            v-if="component.signals?.length"
            class="flex flex-wrap gap-1 mt-1.5"
          >
            <UBadge
              v-for="signal in component.signals"
              :key="signal"
              color="neutral"
              variant="subtle"
              size="sm"
            >
              {{ signal }}
            </UBadge>
          </div>
        </div>
      </div>

      <template v-if="unavailable.length">
        <USeparator />

        <UAccordion
          :items="[{
            label: `${unavailable.length} component${unavailable.length === 1 ? '' : 's'} without data`,
            icon: 'i-lucide-circle-slash',
            slot: 'missing' as const
          }]"
        >
          <template #missing>
            <div class="space-y-2 pt-1">
              <div
                v-for="component in unavailable"
                :key="component.key"
                class="text-sm"
              >
                <div class="flex items-baseline justify-between gap-3">
                  <span class="text-muted">{{ component.label }}</span>
                  <span class="text-xs text-dimmed tabular-nums shrink-0">
                    {{ component.weight }} points unused
                  </span>
                </div>
                <p class="text-xs text-dimmed">
                  {{ component.note }}
                </p>
              </div>
            </div>
          </template>
        </UAccordion>

        <p class="text-xs text-dimmed">
          Missing components are excluded from the average rather than scored zero, so
          the candidate is not penalised for data Block Radar has not fetched.
        </p>
      </template>
    </div>
  </UCard>
</template>
