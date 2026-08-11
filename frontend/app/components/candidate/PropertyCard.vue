<script setup lang="ts">
import type { Title } from '~/types'

const props = defineProps<{ title: Title }>()

const epc = computed(() => props.title.epc)

const confidenceColor = computed(() => {
  switch (epc.value?.match_confidence) {
    case 'high': return 'success'
    case 'medium': return 'warning'
    case 'low': return 'error'
    default: return 'neutral'
  }
})

const certificates = computed(() => props.title.epc_certificates ?? [])
const showAllCertificates = ref(false)
const visibleCertificates = computed(() =>
  showAllCertificates.value ? certificates.value : certificates.value.slice(0, 6)
)
</script>

<template>
  <UCard>
    <template #header>
      <h2 class="font-semibold">
        Property &amp; EPC
      </h2>
    </template>

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 text-sm">
      <div>
        <dt class="text-muted text-xs">
          Tenure
        </dt>
        <dd>
          <UBadge
            :color="title.tenure === 'freehold' ? 'success' : 'neutral'"
            variant="subtle"
          >
            {{ title.tenure_label }}
          </UBadge>
        </dd>
      </div>

      <div>
        <dt class="text-muted text-xs">
          Multiple address indicator
        </dt>
        <dd>
          <UBadge
            :color="title.multiple_address_indicator ? 'success' : 'neutral'"
            variant="subtle"
          >
            {{ title.multiple_address_indicator ? 'Yes' : 'No' }}
          </UBadge>
        </dd>
      </div>

      <div>
        <dt class="text-muted text-xs">
          Estimated units
        </dt>
        <dd class="font-medium tabular-nums flex items-center gap-2">
          {{ title.estimated_unit_count ?? '—' }}
          <UBadge
            v-if="title.unit_count_source"
            :color="title.unit_count_source === 'epc' ? 'success' : 'neutral'"
            variant="subtle"
            size="sm"
          >
            {{ title.unit_count_source === 'epc' ? 'from EPC' : 'from address' }}
          </UBadge>
        </dd>
      </div>

      <div>
        <dt class="text-muted text-xs">
          Postcode
        </dt>
        <dd class="font-medium">
          {{ title.postcode ?? '—' }}
        </dd>
      </div>

      <div>
        <dt class="text-muted text-xs">
          District / county
        </dt>
        <dd class="font-medium">
          {{ [title.district, title.county].filter(Boolean).join(', ') || '—' }}
        </dd>
      </div>

      <div>
        <dt class="text-muted text-xs">
          Region
        </dt>
        <dd class="font-medium">
          {{ title.region ?? '—' }}
        </dd>
      </div>
    </dl>

    <USeparator class="my-4" />

    <div
      v-if="!epc?.enriched_at"
      class="text-sm text-muted"
    >
      <UIcon
        name="i-lucide-circle-slash"
        class="size-4 inline-block align-text-bottom mr-1"
      />
      No EPC enrichment has run for this title yet.
    </div>

    <div
      v-else-if="!epc.is_usable"
      class="text-sm"
    >
      <UAlert
        color="neutral"
        variant="subtle"
        icon="i-lucide-search-x"
        title="No confident EPC match"
        :description="`Checked ${formatDate(epc.enriched_at)}. Any match found was below the confidence threshold, so nothing was attached — a postcode-only match would count the neighbours' flats.`"
      />
    </div>

    <div
      v-else
      class="space-y-4"
    >
      <div class="flex items-center justify-between gap-3">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">
          EPC
        </h3>
        <UBadge
          :color="confidenceColor"
          variant="subtle"
          size="sm"
        >
          {{ epc.match_method_label }} · {{ epc.match_confidence }} confidence
        </UBadge>
      </div>

      <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3 text-sm">
        <div>
          <dt class="text-muted text-xs">
            Worst rating
          </dt>
          <dd>
            <CandidateEpcRating :rating="epc.current_rating" />
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Average SAP
          </dt>
          <dd class="font-medium tabular-nums">
            {{ epc.average_energy_efficiency ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Certificates
          </dt>
          <dd class="font-medium tabular-nums">
            {{ epc.certificate_count }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Total floor area
          </dt>
          <dd class="font-medium tabular-nums">
            {{ epc.total_floor_area ? `${formatNumber(epc.total_floor_area)} m²` : '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Habitable rooms
          </dt>
          <dd class="font-medium tabular-nums">
            {{ epc.habitable_rooms ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Property type
          </dt>
          <dd class="font-medium">
            {{ [epc.property_type, epc.built_form].filter(Boolean).join(' · ') || '—' }}
          </dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="text-muted text-xs">
            Construction age
          </dt>
          <dd class="font-medium">
            {{ epc.construction_age_band ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted text-xs">
            Latest lodgement
          </dt>
          <dd class="font-medium">
            {{ formatDate(epc.latest_lodgement_date) }}
          </dd>
        </div>
        <div class="sm:col-span-3">
          <dt class="text-muted text-xs">
            Main heating
          </dt>
          <dd class="font-medium">
            {{ epc.main_heating ?? '—' }}
          </dd>
        </div>
      </dl>

      <template v-if="certificates.length">
        <USeparator />

        <div>
          <h3 class="text-xs font-semibold uppercase tracking-wide text-muted mb-2">
            Matched certificates
          </h3>

          <div class="divide-y divide-default">
            <div
              v-for="certificate in visibleCertificates"
              :key="certificate.id"
              class="py-2 flex items-center gap-3 text-sm"
            >
              <CandidateEpcRating :rating="certificate.current_energy_rating" />

              <div class="min-w-0 flex-1">
                <p class="truncate">
                  {{ certificate.address }}
                </p>
                <p class="text-xs text-muted">
                  {{ certificate.total_floor_area ? `${certificate.total_floor_area} m²` : 'area unknown' }}
                  · {{ certificate.number_habitable_rooms ?? '?' }} rooms
                  · {{ formatDate(certificate.lodgement_date) }}
                </p>
              </div>

              <UBadge
                v-if="certificate.match?.is_primary"
                color="primary"
                variant="subtle"
                size="sm"
                class="shrink-0"
              >
                primary
              </UBadge>
            </div>
          </div>

          <UButton
            v-if="certificates.length > 6"
            :label="showAllCertificates ? 'Show fewer' : `Show all ${certificates.length}`"
            :trailing-icon="showAllCertificates ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            color="neutral"
            variant="ghost"
            size="xs"
            class="mt-2"
            @click="showAllCertificates = !showAllCertificates"
          />
        </div>
      </template>
    </div>
  </UCard>
</template>
