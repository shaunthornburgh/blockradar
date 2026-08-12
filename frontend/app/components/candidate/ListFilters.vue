<script setup lang="ts">
import type { CandidateFilters, TriState } from '~/composables/useCandidateFilters'
import type { CandidateFilterOptions, PipelineStage } from '~/types'

const props = defineProps<{
  filters: CandidateFilters
  stages: Array<{ value: PipelineStage, label: string }>
  options: CandidateFilterOptions | null
  /** True when the current filters are exactly the "Likely MUFBs" preset. */
  isPreset: boolean
  loading?: boolean
}>()

const emit = defineEmits<{
  update: [patch: Partial<CandidateFilters>]
  preset: []
  clear: []
  refresh: []
}>()

/**
 * The search box is the one control that must not write to the URL on every
 * keystroke — each write is a navigation and a request.
 */
const search = ref(props.filters.search)
const debouncedSearch = useDebouncedRef(search, 300)

watch(debouncedSearch, (value) => {
  if (value !== props.filters.search) {
    emit('update', { search: value })
  }
})

// Back/forward and "Clear" change the URL underneath us; follow it.
watch(() => props.filters.search, (value) => {
  if (value !== search.value) {
    search.value = value
  }
})

function set<K extends keyof CandidateFilters>(key: K, value: CandidateFilters[K]) {
  emit('update', { [key]: value } as Partial<CandidateFilters>)
}

const stageItems = computed(() => [
  { value: 'all', label: 'All stages' },
  ...props.stages.map(stage => ({ value: stage.value, label: stage.label }))
])

const mufbItems = [
  { value: 'any', label: 'Any confidence' },
  { value: 'high', label: 'High confidence' },
  { value: 'medium', label: 'Medium or better' }
]

const sortItems = [
  { value: 'mufb', label: 'MUFB confidence' },
  { value: 'score', label: 'Deal score' },
  { value: 'units', label: 'Units' },
  { value: 'epc_certificate_count', label: 'EPC certificates' },
  { value: 'created_at', label: 'Recently added' },
  { value: 'next_action_at', label: 'Next action' }
]

const triItems = [
  { value: 'any', label: 'Any' },
  { value: 'true', label: 'Yes' },
  { value: 'false', label: 'No' }
]

const regionItems = computed(() => [
  { value: 'all', label: 'All regions' },
  ...(props.options?.regions ?? []).map(region => ({ value: region, label: region }))
])

const postcodeAreaItems = computed(() => [
  { value: 'all', label: 'All postcodes' },
  ...(props.options?.postcode_areas ?? []).map(area => ({ value: area, label: area }))
])

const chips = computed(() => activeFilterChips(props.filters))
</script>

<template>
  <div class="flex flex-col gap-2">
    <UDashboardToolbar>
      <template #left>
        <UInput
          v-model="search"
          icon="i-lucide-search"
          placeholder="Address, title number, postcode, company…"
          class="w-80"
          :aria-label="'Search candidates'"
        />

        <USelect
          :model-value="filters.minMufb"
          :items="mufbItems"
          value-key="value"
          icon="i-lucide-building"
          class="w-48"
          aria-label="Minimum MUFB confidence"
          @update:model-value="set('minMufb', $event)"
        />

        <USelect
          :model-value="filters.stage"
          :items="stageItems"
          value-key="value"
          class="w-40"
          aria-label="Pipeline stage"
          @update:model-value="set('stage', $event as PipelineStage | 'all')"
        />

        <UPopover>
          <UButton
            icon="i-lucide-sliders-horizontal"
            color="neutral"
            variant="outline"
            label="Filters"
          >
            <template #trailing>
              <UBadge
                v-if="chips.length"
                :label="String(chips.length)"
                size="sm"
                variant="solid"
              />
            </template>
          </UButton>

          <template #content>
            <div class="grid grid-cols-2 gap-4 p-4 w-[34rem]">
              <UFormField label="Region">
                <USelectMenu
                  :model-value="filters.region"
                  :items="regionItems"
                  value-key="value"
                  class="w-full"
                  @update:model-value="set('region', $event)"
                />
              </UFormField>

              <UFormField label="Postcode area">
                <USelectMenu
                  :model-value="filters.postcodeArea"
                  :items="postcodeAreaItems"
                  value-key="value"
                  class="w-full"
                  @update:model-value="set('postcodeArea', $event)"
                />
              </UFormField>

              <UFormField
                label="Minimum units"
                help="Counted EPCs where they exist, otherwise the address estimate."
              >
                <UInputNumber
                  :model-value="filters.minUnits ?? undefined"
                  :min="1"
                  :max="500"
                  placeholder="Any"
                  class="w-full"
                  @update:model-value="set('minUnits', Number.isFinite($event as number) ? ($event as number) : null)"
                />
              </UFormField>

              <UFormField
                label="Minimum EPC certificates"
                help="Two or more is the strongest evidence of a block."
              >
                <UInputNumber
                  :model-value="filters.minEpcCertificates ?? undefined"
                  :min="0"
                  :max="500"
                  placeholder="Any"
                  class="w-full"
                  @update:model-value="set('minEpcCertificates', Number.isFinite($event as number) ? ($event as number) : null)"
                />
              </UFormField>

              <UFormField label="Score at least">
                <UInputNumber
                  :model-value="filters.minScore ?? undefined"
                  :min="0"
                  :max="100"
                  :step="5"
                  placeholder="Any"
                  class="w-full"
                  @update:model-value="set('minScore', Number.isFinite($event as number) ? ($event as number) : null)"
                />
              </UFormField>

              <UFormField label="Score at most">
                <UInputNumber
                  :model-value="filters.maxScore ?? undefined"
                  :min="0"
                  :max="100"
                  :step="5"
                  placeholder="Any"
                  class="w-full"
                  @update:model-value="set('maxScore', Number.isFinite($event as number) ? ($event as number) : null)"
                />
              </UFormField>

              <UFormField label="Matched EPC">
                <USelect
                  :model-value="filters.hasEpc"
                  :items="triItems"
                  value-key="value"
                  class="w-full"
                  @update:model-value="set('hasEpc', $event as TriState)"
                />
              </UFormField>

              <UFormField label="Registered charges">
                <USelect
                  :model-value="filters.hasCharges"
                  :items="triItems"
                  value-key="value"
                  class="w-full"
                  @update:model-value="set('hasCharges', $event as TriState)"
                />
              </UFormField>

              <div class="col-span-2 flex flex-col gap-3 border-t border-default pt-3">
                <USwitch
                  :model-value="filters.companyDistressed"
                  label="Distressed owner only"
                  description="Overdue filings, insolvency history, or in an insolvency process."
                  @update:model-value="set('companyDistressed', $event)"
                />

                <USwitch
                  :model-value="filters.includeUnknownUnits"
                  label="Keep unknown unit counts"
                  description="Titles whose unit count could not be worked out still pass the minimum."
                  @update:model-value="set('includeUnknownUnits', $event)"
                />

                <USwitch
                  :model-value="filters.archived"
                  label="Show archived"
                  @update:model-value="set('archived', $event)"
                />
              </div>
            </div>
          </template>
        </UPopover>
      </template>

      <template #right>
        <USelect
          :model-value="filters.sort"
          :items="sortItems"
          value-key="value"
          icon="i-lucide-arrow-up-down"
          class="w-48"
          aria-label="Sort by"
          @update:model-value="set('sort', $event)"
        />

        <UButton
          :icon="filters.direction === 'desc' ? 'i-lucide-arrow-down-wide-narrow' : 'i-lucide-arrow-up-narrow-wide'"
          color="neutral"
          variant="ghost"
          :aria-label="filters.direction === 'desc' ? 'Sorted high to low' : 'Sorted low to high'"
          @click="set('direction', filters.direction === 'desc' ? 'asc' : 'desc')"
        />

        <UButton
          icon="i-lucide-refresh-cw"
          color="neutral"
          variant="ghost"
          :loading="loading"
          aria-label="Refresh"
          @click="emit('refresh')"
        />
      </template>
    </UDashboardToolbar>

    <div class="flex flex-wrap items-center gap-2 px-4 pb-2">
      <UButton
        icon="i-lucide-building-2"
        size="xs"
        :color="isPreset ? 'primary' : 'neutral'"
        :variant="isPreset ? 'solid' : 'outline'"
        label="Likely MUFBs"
        @click="emit('preset')"
      />

      <UBadge
        v-for="chip in chips"
        :key="chip.key"
        color="neutral"
        variant="subtle"
        size="md"
        class="gap-1"
      >
        {{ chip.label }}
        <UButton
          icon="i-lucide-x"
          size="xs"
          color="neutral"
          variant="link"
          class="p-0"
          :aria-label="`Remove filter ${chip.label}`"
          @click="emit('update', clearedFilter(chip.key))"
        />
      </UBadge>

      <UButton
        v-if="chips.length"
        icon="i-lucide-x"
        size="xs"
        color="neutral"
        variant="ghost"
        label="Clear all"
        @click="emit('clear')"
      />

      <span
        v-else
        class="text-xs text-muted"
      >
        No filters — showing every candidate.
      </span>
    </div>
  </div>
</template>
