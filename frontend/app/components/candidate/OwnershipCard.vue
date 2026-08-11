<script setup lang="ts">
import type { Company, Title } from '~/types'

const props = defineProps<{
  title: Title
  company?: Company
}>()

const statusColor = computed(() => {
  const company = props.company
  if (!company) return 'neutral'
  if (company.is_dissolved) return 'error'
  if (company.is_distressed) return 'warning'

  return company.status === 'active' ? 'success' : 'neutral'
})

/** The motivation signals, surfaced as chips rather than buried in the score. */
const signals = computed(() => {
  const company = props.company
  if (!company?.is_enriched) return []

  const out: Array<{ label: string, color: 'error' | 'warning' | 'info' }> = []

  if (company.accounts_overdue) out.push({ label: 'Accounts overdue', color: 'error' })
  if (company.confirmation_statement_overdue) out.push({ label: 'Confirmation statement overdue', color: 'error' })
  if (company.is_distressed) out.push({ label: `In ${company.status?.replace(/-/g, ' ')}`, color: 'error' })
  if (company.is_dissolved) out.push({ label: 'Dissolved', color: 'error' })
  if (company.has_insolvency_history && !company.is_distressed) out.push({ label: 'Past insolvency', color: 'warning' })
  if (company.has_charges) {
    out.push({
      label: company.charges_count
        ? `${company.charges_count} registered charge${company.charges_count === 1 ? '' : 's'}`
        : 'Registered charges',
      color: 'info'
    })
  }

  return out
})

const companyHouseUrl = computed(() =>
  props.company
    ? `https://find-and-update.company-information.service.gov.uk/company/${props.company.company_number}`
    : null
)
</script>

<template>
  <UCard>
    <template #header>
      <h2 class="font-semibold">
        Ownership
      </h2>
    </template>

    <div
      v-if="!company"
      class="text-sm text-muted"
    >
      <p class="mb-1">
        No company matched to this title.
      </p>
      <p class="text-xs text-dimmed">
        CCOD recorded the proprietor as
        <span class="font-medium">{{ title.proprietor_name ?? 'unknown' }}</span>
        with no usable registration number.
      </p>
    </div>

    <div
      v-else
      class="space-y-4"
    >
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="font-medium truncate">
            {{ company.name }}
          </p>
          <p class="text-xs text-muted font-mono">
            {{ company.company_number }}
          </p>
        </div>

        <UBadge
          :color="statusColor"
          variant="subtle"
          class="shrink-0"
        >
          {{ company.status?.replace(/-/g, ' ') ?? 'unknown' }}
        </UBadge>
      </div>

      <UAlert
        v-if="!company.is_enriched"
        color="neutral"
        variant="subtle"
        icon="i-lucide-info"
        description="Not yet enriched from Companies House, so the two motivation components are excluded from the score. Run companies:enrich."
      />

      <div
        v-if="signals.length"
        class="flex flex-wrap gap-1.5"
      >
        <UBadge
          v-for="signal in signals"
          :key="signal.label"
          :color="signal.color"
          variant="subtle"
        >
          {{ signal.label }}
        </UBadge>
      </div>

      <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 text-sm">
        <div>
          <dt class="text-muted text-xs">
            Incorporated
          </dt>
          <dd class="font-medium">
            {{ formatDate(company.incorporated_on) }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Proprietor added to title
          </dt>
          <dd class="font-medium">
            {{ formatDate(title.date_proprietor_added) }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Last accounts
          </dt>
          <dd class="font-medium">
            {{ formatDate(company.accounts_last_made_up_to) }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Accounts next due
          </dt>
          <dd
            class="font-medium"
            :class="company.accounts_overdue ? 'text-error' : ''"
          >
            {{ formatDate(company.accounts_next_due) }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Registered office
          </dt>
          <dd class="font-medium">
            {{ company.registered_office_postcode ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            SIC codes
          </dt>
          <dd class="font-medium">
            {{ company.sic_codes?.length ? company.sic_codes.join(', ') : '—' }}
          </dd>
        </div>
      </dl>

      <UButton
        v-if="companyHouseUrl"
        :to="companyHouseUrl"
        target="_blank"
        rel="noopener noreferrer"
        label="View on Companies House"
        icon="i-lucide-external-link"
        color="neutral"
        variant="outline"
        size="sm"
        block
      />
    </div>
  </UCard>
</template>
