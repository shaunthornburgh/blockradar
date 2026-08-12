<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { CandidateFilters } from '~/composables/useCandidateFilters'
import type {
  ApiCollection,
  ApiResource,
  AppMeta,
  Candidate,
  CandidateFilterOptions,
  PipelineStage
} from '~/types'

useSeoMeta({ title: 'Candidates' })

const api = useApi()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const PER_PAGE = 20

const { data: meta } = await useAsyncData(
  'meta',
  () => api<ApiResource<AppMeta>>('/meta')
)

const { data: filterOptions } = await useAsyncData(
  'candidate-filter-options',
  () => api<ApiResource<CandidateFilterOptions>>('/candidates/filter-options')
)

const preset = computed(() => presetFilters(meta.value?.data.candidate_defaults))

/**
 * Landing on a bare /candidates applies the "Likely MUFBs" preset — but by
 * writing it into the URL rather than applying it behind the scenes, so the
 * filter bar shows what is being asked for and "Clear all" genuinely reveals
 * the whole population.
 */
if (Object.keys(route.query).length === 0) {
  await navigateTo(
    { path: route.path, query: queryFromFilters(preset.value) },
    { replace: true }
  )
}

const filters = computed<CandidateFilters>(() => filtersFromQuery(route.query))

const isPreset = computed(() => {
  const current = queryFromFilters({ ...filters.value, page: 1, sort: preset.value.sort, direction: preset.value.direction })

  return JSON.stringify(current) === JSON.stringify(queryFromFilters(preset.value))
})

/**
 * Every filter change is a URL change; the URL is the only state. `replace`
 * rather than `push`, so Back leaves the page instead of walking through
 * every keystroke of a search.
 */
function applyFilters(next: CandidateFilters, { keepPage = false } = {}) {
  return router.replace({
    path: route.path,
    query: queryFromFilters({ ...next, page: keepPage ? next.page : 1 })
  })
}

function update(patch: Partial<CandidateFilters>) {
  return applyFilters({ ...filters.value, ...patch })
}

function goToPage(page: number) {
  return applyFilters({ ...filters.value, page }, { keepPage: true })
}

const query = computed(() => apiQueryFromFilters(filters.value, PER_PAGE))

const { data, pending, refresh } = await useAsyncData(
  'candidates',
  () => api<ApiCollection<Candidate>>('/candidates', { query: query.value }),
  { watch: [query] }
)

const rows = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta.total ?? 0)
const stages = computed(() => meta.value?.data.stages ?? [])

const columns: TableColumn<Candidate>[] = [
  { id: 'mufb', header: 'MUFB' },
  { accessorKey: 'score', header: 'Score' },
  { id: 'address', header: 'Property' },
  { id: 'units', header: 'Units' },
  { id: 'epc', header: 'EPC' },
  { id: 'company', header: 'Proprietor' },
  { id: 'stage', header: 'Stage' },
  { id: 'actions', header: '' }
]

function mufbColor(level: Candidate['mufb']['level']) {
  switch (level) {
    case 'high':
      return 'success'
    case 'medium':
      return 'warning'
    default:
      return 'neutral'
  }
}

async function changeStage(candidate: Candidate, next: PipelineStage) {
  try {
    await api(`/candidates/${candidate.id}`, {
      method: 'PATCH',
      body: { stage: next }
    })

    toast.add({
      title: 'Stage updated',
      description: candidate.title?.title_number ?? `Candidate ${candidate.id}`,
      color: 'success'
    })

    await refresh()
  } catch {
    toast.add({
      title: 'Could not update the stage',
      color: 'error'
    })
  }
}

function stageMenu(candidate: Candidate) {
  return [stages.value.map(stage => ({
    label: stage.label,
    icon: candidate.stage === stage.value ? 'i-lucide-check' : undefined,
    onSelect: () => changeStage(candidate, stage.value)
  }))]
}
</script>

<template>
  <UDashboardPanel id="candidates">
    <template #header>
      <UDashboardNavbar title="Candidates">
        <template #right>
          <UBadge
            color="neutral"
            variant="subtle"
          >
            {{ formatNumber(total) }} matching
          </UBadge>
        </template>
      </UDashboardNavbar>

      <CandidateListFilters
        :filters="filters"
        :stages="stages"
        :options="filterOptions?.data ?? null"
        :is-preset="isPreset"
        :loading="pending"
        @update="update"
        @preset="applyFilters(preset)"
        @clear="applyFilters(emptyCandidateFilters())"
        @refresh="refresh()"
      />
    </template>

    <template #body>
      <UTable
        :data="rows"
        :columns="columns"
        :loading="pending"
        class="flex-1"
      >
        <template #mufb-cell="{ row }">
          <UTooltip
            :text="row.original.mufb.signals.join(' · ') || 'No block-of-flats evidence yet'"
            :disabled="!row.original.mufb.signals.length"
          >
            <UBadge
              :color="mufbColor(row.original.mufb.level)"
              variant="subtle"
              class="tabular-nums"
            >
              {{ row.original.mufb.confidence }}
            </UBadge>
          </UTooltip>
        </template>

        <template #score-cell="{ row }">
          <UBadge
            :color="scoreColor(row.original.score)"
            variant="subtle"
            class="tabular-nums"
          >
            {{ row.original.score }}
          </UBadge>
        </template>

        <template #address-cell="{ row }">
          <NuxtLink
            :to="`/candidates/${row.original.id}`"
            class="block min-w-0 max-w-md group"
          >
            <p class="font-medium truncate group-hover:text-primary transition-colors">
              {{ row.original.title?.property_address }}
            </p>
            <p class="text-xs text-muted truncate">
              {{ row.original.title?.title_number }}
              · {{ row.original.title?.postcode }}
              · {{ row.original.title?.region }}
            </p>
          </NuxtLink>
        </template>

        <template #units-cell="{ row }">
          <div class="tabular-nums">
            <span>{{ row.original.units ?? '—' }}</span>
            <p
              v-if="row.original.units_source"
              class="text-xs text-muted"
            >
              {{ row.original.units_source === 'epc' ? 'from EPCs' : 'estimated' }}
            </p>
          </div>
        </template>

        <template #epc-cell="{ row }">
          <div
            v-if="row.original.title?.epc?.is_usable"
            class="flex items-center gap-2"
          >
            <CandidateEpcRating :rating="row.original.title.epc.current_rating" />
            <span class="text-xs text-muted whitespace-nowrap tabular-nums">
              {{ row.original.title.epc.certificate_count }} cert{{ row.original.title.epc.certificate_count === 1 ? '' : 's' }}
            </span>
          </div>
          <span
            v-else
            class="text-xs text-muted"
          >
            No match
          </span>
        </template>

        <template #company-cell="{ row }">
          <div class="min-w-0 max-w-xs">
            <p class="truncate">
              {{ row.original.title?.company?.name ?? '—' }}
            </p>
            <p
              v-if="row.original.title?.company?.distress_signals?.length"
              class="text-xs text-warning truncate"
            >
              <UIcon
                name="i-lucide-triangle-alert"
                class="align-[-2px]"
              />
              {{ row.original.title.company.distress_signals.join(' · ') }}
            </p>
            <p
              v-else
              class="text-xs text-muted truncate"
            >
              {{ row.original.title?.company?.company_number }}
              <template v-if="row.original.title?.company?.has_charges">
                · has charges
              </template>
            </p>
          </div>
        </template>

        <template #stage-cell="{ row }">
          <UDropdownMenu :items="stageMenu(row.original)">
            <UButton
              :label="row.original.stage_label"
              :color="stageColor(row.original.stage)"
              variant="subtle"
              size="xs"
              trailing-icon="i-lucide-chevron-down"
            />
          </UDropdownMenu>
        </template>

        <template #actions-cell="{ row }">
          <UButton
            :to="`/candidates/${row.original.id}`"
            icon="i-lucide-arrow-right"
            color="neutral"
            variant="ghost"
            size="xs"
            :aria-label="`Open ${row.original.title?.title_number}`"
          />
        </template>

        <template #empty>
          <div class="py-8 text-center text-sm text-muted">
            <p>No candidates match these filters.</p>
            <UButton
              class="mt-2"
              size="xs"
              color="neutral"
              variant="outline"
              label="Clear all filters"
              @click="applyFilters(emptyCandidateFilters())"
            />
          </div>
        </template>
      </UTable>

      <div class="flex justify-center pt-4">
        <UPagination
          :page="filters.page"
          :total="total"
          :items-per-page="PER_PAGE"
          @update:page="goToPage"
        />
      </div>
    </template>
  </UDashboardPanel>
</template>
