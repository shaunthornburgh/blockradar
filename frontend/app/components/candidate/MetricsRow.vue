<script setup lang="ts">
import type { Candidate } from '~/types'

const props = defineProps<{ candidate: Candidate }>()

const title = computed(() => props.candidate.title)
const company = computed(() => title.value?.company)
const epc = computed(() => title.value?.epc)

/** Whole years since the proprietor was registered on the title. */
const ownershipYears = computed(() => {
  const since = title.value?.date_proprietor_added
  if (!since) return null

  const years = (Date.now() - new Date(since).getTime()) / (365.25 * 24 * 60 * 60 * 1000)

  return years >= 0 ? Math.floor(years) : null
})

const companySummary = computed(() => {
  const c = company.value

  if (!c) return { value: 'No company', hint: 'Unmatched proprietor', color: 'neutral' as const }
  if (!c.is_enriched) return { value: 'Not enriched', hint: 'Run companies:enrich', color: 'neutral' as const }
  if (c.is_dissolved) return { value: 'Dissolved', hint: 'High risk — may be bona vacantia', color: 'error' as const }
  if (c.is_distressed) return { value: c.status?.replace(/-/g, ' ') ?? 'Distressed', hint: 'Insolvency process', color: 'error' as const }

  const overdue = [
    c.accounts_overdue ? 'accounts' : null,
    c.confirmation_statement_overdue ? 'confirmation statement' : null
  ].filter(Boolean)

  if (overdue.length) {
    return { value: 'Overdue filings', hint: overdue.join(' & ') + ' overdue', color: 'warning' as const }
  }

  return { value: 'Active', hint: 'Filings up to date', color: 'success' as const }
})

const metrics = computed(() => [
  {
    label: 'Estimated units',
    value: title.value?.estimated_unit_count?.toString() ?? '—',
    hint: title.value?.unit_count_source === 'epc'
      ? 'Counted from EPC certificates'
      : title.value?.unit_count_source === 'address'
        ? 'Parsed from the address'
        : 'Unknown',
    icon: 'i-lucide-layers',
    color: 'neutral' as const
  },
  {
    label: 'EPC',
    value: epc.value?.is_usable && epc.value.current_rating
      ? `${epc.value.current_rating}${epc.value.average_energy_efficiency ? ` · SAP ${epc.value.average_energy_efficiency}` : ''}`
      : '—',
    hint: epc.value?.is_usable
      ? `Worst of ${epc.value.certificate_count} certificate${epc.value.certificate_count === 1 ? '' : 's'}`
      : 'No confident match',
    icon: 'i-lucide-zap',
    color: 'neutral' as const
  },
  {
    label: 'Floor area',
    value: epc.value?.total_floor_area ? `${formatNumber(epc.value.total_floor_area)} m²` : '—',
    hint: epc.value?.habitable_rooms ? `${epc.value.habitable_rooms} habitable rooms` : 'From EPC certificates',
    icon: 'i-lucide-ruler',
    color: 'neutral' as const
  },
  {
    label: 'Price paid',
    value: formatMoney(title.value?.price_paid, { compact: true }),
    hint: title.value?.price_paid && title.value.estimated_unit_count
      ? `${formatMoney(Math.round(title.value.price_paid / title.value.estimated_unit_count), { compact: true })} per unit`
      : 'Not recorded in CCOD',
    icon: 'i-lucide-pound-sterling',
    color: 'neutral' as const
  },
  {
    label: 'Held for',
    value: ownershipYears.value !== null ? `${ownershipYears.value} yr` : '—',
    hint: title.value?.date_proprietor_added
      ? `Since ${formatDate(title.value.date_proprietor_added)}`
      : 'No proprietor date',
    icon: 'i-lucide-hourglass',
    color: 'neutral' as const
  },
  {
    label: 'Company',
    value: companySummary.value.value,
    hint: companySummary.value.hint,
    icon: 'i-lucide-building-2',
    color: companySummary.value.color
  }
])
</script>

<template>
  <div class="grid gap-3 grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
    <UCard
      v-for="metric in metrics"
      :key="metric.label"
      :ui="{ body: 'p-3 sm:p-4' }"
    >
      <div class="flex items-start justify-between gap-2">
        <p class="text-xs text-muted truncate">
          {{ metric.label }}
        </p>
        <UIcon
          :name="metric.icon"
          class="size-4 text-dimmed shrink-0"
        />
      </div>

      <p
        class="text-lg font-semibold mt-1 truncate capitalize"
        :class="{
          'text-error': metric.color === 'error',
          'text-warning': metric.color === 'warning',
          'text-success': metric.color === 'success'
        }"
        :title="metric.value"
      >
        {{ metric.value }}
      </p>

      <p
        class="text-xs text-dimmed mt-0.5 line-clamp-2"
        :title="metric.hint"
      >
        {{ metric.hint }}
      </p>
    </UCard>
  </div>
</template>
