<script setup lang="ts">
import type { ApiResource, Title } from '~/types'

/**
 * Read-only research view of one CCOD title.
 *
 * The full inventory, not a second deal CRM: no stage changes, no notes, no
 * editable estimates. Where a title has reached the pipeline this page links
 * across to the candidate and stops there.
 */
const route = useRoute()
const api = useApi()
const toast = useToast()

const id = computed(() => route.params.id as string)

const { data, error, refresh } = await useAsyncData(
  () => `title-${id.value}`,
  () => api<ApiResource<Title>>(`/titles/${id.value}`),
  { watch: [id] }
)

const title = computed(() => data.value?.data)
const company = computed(() => title.value?.company ?? undefined)

useSeoMeta({
  title: () => title.value?.property_address ?? 'Title'
})

async function copyTitleNumber() {
  if (!title.value) return

  try {
    await navigator.clipboard.writeText(title.value.title_number)
    toast.add({ title: 'Title number copied', color: 'success' })
  } catch {
    toast.add({ title: 'Could not copy', color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="title-detail">
    <template #header>
      <UDashboardNavbar :title="title?.title_number ?? 'Title'">
        <template #leading>
          <UButton
            to="/titles"
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            aria-label="Back to titles"
          />
        </template>

        <template #right>
          <UButton
            v-if="title?.candidate"
            :to="`/candidates/${title.candidate.id}`"
            label="Candidate"
            icon="i-lucide-target"
            color="primary"
            variant="subtle"
            size="sm"
          />
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            aria-label="Refresh"
            @click="refresh()"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Could not load this title"
        :description="error.message"
      />

      <div
        v-else-if="title"
        class="space-y-6"
      >
        <!-- Header -->
        <UCard>
          <div class="flex flex-col lg:flex-row lg:items-start gap-4">
            <div class="min-w-0 flex-1">
              <h1 class="text-xl font-semibold leading-snug break-words">
                {{ title.property_address }}
              </h1>

              <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5 text-sm text-muted">
                <UButton
                  :label="title.title_number"
                  icon="i-lucide-copy"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  class="font-mono -ml-2"
                  @click="copyTitleNumber"
                />
                <span v-if="title.postcode">{{ title.postcode }}</span>
                <span v-if="title.region">{{ title.region }}</span>

                <UBadge
                  :color="title.tenure === 'freehold' ? 'success' : 'neutral'"
                  variant="subtle"
                  size="sm"
                >
                  {{ title.tenure_label }}
                </UBadge>

                <UBadge
                  v-if="title.multiple_address_indicator"
                  color="primary"
                  variant="subtle"
                  size="sm"
                >
                  Multiple addresses
                </UBadge>
                <UBadge
                  v-else
                  color="neutral"
                  variant="subtle"
                  size="sm"
                >
                  Single address
                </UBadge>

                <UBadge
                  v-if="title.additional_proprietor_indicator"
                  color="neutral"
                  variant="subtle"
                  size="sm"
                >
                  Additional proprietors
                </UBadge>
              </div>
            </div>

            <div
              v-if="title.candidate"
              class="flex items-center gap-2 shrink-0"
            >
              <UBadge
                :color="stageColor(title.candidate.stage)"
                variant="subtle"
                size="lg"
              >
                {{ title.candidate.stage_label }}
              </UBadge>
              <UBadge
                :color="scoreColor(title.candidate.score)"
                variant="subtle"
                size="lg"
                class="tabular-nums"
              >
                {{ title.candidate.score }}
              </UBadge>
            </div>
          </div>
        </UCard>

        <!-- Key facts: units and source, price paid, ownership, EPC summary -->
        <CandidateMetricsRow :title="title" />

        <CandidateResearchLinks
          :title="title"
          :company="company"
        />

        <div class="grid gap-6 xl:grid-cols-3">
          <div class="xl:col-span-2 space-y-6">
            <CandidatePropertyCard :title="title" />
          </div>

          <div class="space-y-6">
            <TitlePipelineCard
              v-if="title.pipeline"
              :pipeline="title.pipeline"
              :candidate="title.candidate"
            />

            <CandidateOwnershipCard
              :title="title"
              :company="company"
            />

            <UCard>
              <template #header>
                <h2 class="font-semibold">
                  Record
                </h2>
              </template>

              <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 text-sm">
                <div>
                  <dt class="text-muted text-xs">
                    Proprietorship category
                  </dt>
                  <dd class="font-medium">
                    {{ title.proprietorship_category ?? '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted text-xs">
                    UPRN
                  </dt>
                  <dd class="font-medium font-mono">
                    {{ title.uprn ?? title.epc?.uprn ?? '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted text-xs">
                    First seen in CCOD
                  </dt>
                  <dd class="font-medium">
                    {{ formatDate(title.first_seen_at) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted text-xs">
                    Last seen in CCOD
                  </dt>
                  <dd class="font-medium">
                    {{ formatDate(title.last_seen_at) }}
                  </dd>
                </div>
              </dl>
            </UCard>
          </div>
        </div>
      </div>

      <div
        v-else
        class="space-y-4"
      >
        <USkeleton class="h-24 w-full" />
        <USkeleton class="h-20 w-full" />
        <USkeleton class="h-64 w-full" />
      </div>
    </template>
  </UDashboardPanel>
</template>
