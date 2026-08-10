<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { ApiCollection, Title } from '~/types'

useSeoMeta({ title: 'Titles' })

const api = useApi()

const search = ref('')
const splitOnly = ref(true)
const page = ref(1)

const debouncedSearch = useDebouncedRef(search, 300)

watch([debouncedSearch, splitOnly], () => {
  page.value = 1
})

const query = computed(() => ({
  page: page.value,
  per_page: 20,
  split_only: splitOnly.value,
  ...(debouncedSearch.value ? { search: debouncedSearch.value } : {})
}))

const { data, pending, refresh } = await useAsyncData(
  'titles',
  () => api<ApiCollection<Title>>('/titles', { query: query.value }),
  { watch: [query] }
)

const rows = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta.total ?? 0)

const columns: TableColumn<Title>[] = [
  { accessorKey: 'title_number', header: 'Title' },
  { id: 'address', header: 'Property' },
  { id: 'proprietor', header: 'Proprietor' },
  { accessorKey: 'tenure_label', header: 'Tenure' },
  { id: 'price', header: 'Price paid' },
  { id: 'added', header: 'Proprietor added' }
]
</script>

<template>
  <UDashboardPanel id="titles">
    <template #header>
      <UDashboardNavbar title="Titles">
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
          <USwitch
            v-model="splitOnly"
            label="Freehold + multiple address only"
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
        <template #address-cell="{ row }">
          <div class="min-w-0 max-w-md">
            <p class="font-medium truncate">
              {{ row.original.property_address }}
            </p>
            <p class="text-xs text-muted truncate">
              {{ row.original.postcode }} · {{ row.original.region }}
            </p>
          </div>
        </template>

        <template #proprietor-cell="{ row }">
          <span class="truncate">
            {{ row.original.company?.name ?? row.original.proprietor_name ?? '—' }}
          </span>
        </template>

        <template #price-cell="{ row }">
          <span class="tabular-nums">{{ formatMoney(row.original.price_paid, { compact: true }) }}</span>
        </template>

        <template #added-cell="{ row }">
          <span class="tabular-nums">{{ formatDate(row.original.date_proprietor_added) }}</span>
        </template>

        <template #empty>
          <div class="py-8 text-center text-sm text-muted">
            No titles yet. The CCOD importer will fill this in.
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
