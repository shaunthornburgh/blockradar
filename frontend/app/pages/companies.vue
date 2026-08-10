<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ApiCollection, Company } from '~/types'

useSeoMeta({ title: 'Companies' })

const api = useApi()

const search = ref('')
const page = ref(1)

const debouncedSearch = useDebouncedRef(search, 300)

watch(debouncedSearch, () => {
  page.value = 1
})

const query = computed(() => ({
  page: page.value,
  per_page: 20,
  ...(debouncedSearch.value ? { search: debouncedSearch.value } : {})
}))

const { data, pending, refresh } = await useAsyncData(
  'companies',
  () => api<ApiCollection<Company>>('/companies', { query: query.value }),
  { watch: [query] }
)

const rows = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta.total ?? 0)

const columns: TableColumn<Company>[] = [
  { accessorKey: 'name', header: 'Company' },
  { accessorKey: 'company_number', header: 'Number' },
  { id: 'status', header: 'Status' },
  { id: 'charges', header: 'Charges' },
  { id: 'enriched', header: 'Enriched' }
]
</script>

<template>
  <UDashboardPanel id="companies">
    <template #header>
      <UDashboardNavbar title="Companies">
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
            placeholder="Company name or number…"
            class="w-72"
          />
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
        <template #status-cell="{ row }">
          <UBadge
            :color="row.original.status === 'active' ? 'success' : 'neutral'"
            variant="subtle"
          >
            {{ row.original.status ?? 'unknown' }}
          </UBadge>
        </template>

        <template #charges-cell="{ row }">
          <span
            v-if="row.original.has_charges"
            class="text-sm"
          >
            {{ row.original.charges_count ?? '—' }}
          </span>
          <span
            v-else
            class="text-sm text-muted"
          >None</span>
        </template>

        <template #enriched-cell="{ row }">
          <span
            class="text-sm"
            :class="row.original.enriched_at ? '' : 'text-muted'"
          >
            {{ row.original.enriched_at ? formatDate(row.original.enriched_at) : 'Pending' }}
          </span>
        </template>

        <template #empty>
          <div class="py-8 text-center text-sm text-muted">
            No companies yet.
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
