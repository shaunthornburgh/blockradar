<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ApiCollection, ApiResource, Candidate, PipelineStage } from '~/types'

useSeoMeta({ title: 'Candidates' })

const api = useApi()
const toast = useToast()

const search = ref('')
const stage = ref<PipelineStage | 'all'>('all')
const minScore = ref(0)
const page = ref(1)

interface StageOption { value: PipelineStage | 'all', label: string }

const { data: meta } = await useAsyncData(
  'meta',
  () => api<ApiResource<{ stages: Array<{ value: PipelineStage, label: string }> }>>('/meta')
)

const stageOptions = computed<StageOption[]>(() => [
  { value: 'all', label: 'All stages' },
  ...(meta.value?.data.stages ?? []).map(s => ({ value: s.value, label: s.label }))
])

const debouncedSearch = useDebouncedRef(search, 300)

// Filter changes must reset paging, otherwise page 4 of a narrower result set
// comes back empty.
watch([debouncedSearch, stage, minScore], () => {
  page.value = 1
})

const query = computed(() => ({
  page: page.value,
  per_page: 20,
  ...(debouncedSearch.value ? { search: debouncedSearch.value } : {}),
  ...(stage.value !== 'all' ? { stage: stage.value } : {}),
  ...(minScore.value > 0 ? { min_score: minScore.value } : {})
}))

const { data, pending, refresh } = await useAsyncData(
  'candidates',
  () => api<ApiCollection<Candidate>>('/candidates', { query: query.value }),
  { watch: [query] }
)

const rows = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta.total ?? 0)

const columns: TableColumn<Candidate>[] = [
  { accessorKey: 'score', header: 'Score' },
  { id: 'address', header: 'Property' },
  { id: 'company', header: 'Proprietor' },
  { accessorKey: 'estimated_units', header: 'Units' },
  { id: 'yield', header: 'Yield' },
  { id: 'uplift', header: 'Est. uplift' },
  { id: 'stage', header: 'Stage' }
]

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
  return [(meta.value?.data.stages ?? []).map(s => ({
    label: s.label,
    icon: candidate.stage === s.value ? 'i-lucide-check' : undefined,
    onSelect: () => changeStage(candidate, s.value)
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
            {{ formatNumber(total) }} total
          </UBadge>
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <template #left>
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Address, title number, postcode…"
            class="w-72"
          />
          <USelect
            v-model="stage"
            :items="stageOptions"
            value-key="value"
            class="w-44"
          />
          <div class="flex items-center gap-2">
            <span class="text-sm text-muted whitespace-nowrap">Min score</span>
            <UInputNumber
              v-model="minScore"
              :min="0"
              :max="100"
              :step="5"
              class="w-28"
            />
          </div>
        </template>

        <template #right>
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            :loading="pending"
            aria-label="Refresh"
            @click="refresh()"
          />
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <UTable
        :data="rows"
        :columns="columns"
        :loading="pending"
        class="flex-1"
      >
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
          <div class="min-w-0 max-w-md">
            <p class="font-medium truncate">
              {{ row.original.title?.property_address }}
            </p>
            <p class="text-xs text-muted truncate">
              {{ row.original.title?.title_number }}
              · {{ row.original.title?.postcode }}
              · {{ row.original.title?.region }}
            </p>
          </div>
        </template>

        <template #company-cell="{ row }">
          <div class="min-w-0 max-w-xs">
            <p class="truncate">
              {{ row.original.title?.company?.name ?? '—' }}
            </p>
            <p class="text-xs text-muted truncate">
              {{ row.original.title?.company?.company_number }}
              <template v-if="row.original.title?.company?.has_charges">
                · has charges
              </template>
            </p>
          </div>
        </template>

        <template #estimated_units-cell="{ row }">
          <span class="tabular-nums">{{ row.original.estimated_units ?? '—' }}</span>
        </template>

        <template #yield-cell="{ row }">
          <span class="tabular-nums">{{ formatPercent(row.original.gross_yield) }}</span>
        </template>

        <template #uplift-cell="{ row }">
          <span class="tabular-nums">
            {{ formatMoney(row.original.estimated_uplift, { compact: true }) }}
          </span>
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

        <template #empty>
          <div class="py-8 text-center text-sm text-muted">
            No candidates match these filters.
          </div>
        </template>
      </UTable>

      <div class="flex justify-center pt-4">
        <UPagination
          v-model:page="page"
          :total="total"
          :items-per-page="20"
        />
      </div>
    </template>
  </UDashboardPanel>
</template>
