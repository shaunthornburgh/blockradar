<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { ApiResource, Candidate, PipelineStage } from '~/types'

const route = useRoute()
const api = useApi()
const toast = useToast()

const id = computed(() => route.params.id as string)

const { data, error, refresh } = await useAsyncData(
  () => `candidate-${id.value}`,
  () => api<ApiResource<Candidate>>(`/candidates/${id.value}`),
  { watch: [id] }
)

const { data: meta } = await useAsyncData(
  'meta',
  () => api<ApiResource<{ stages: Array<{ value: PipelineStage, label: string }> }>>('/meta')
)

const candidate = computed(() => data.value?.data)
const title = computed(() => candidate.value?.title)
const company = computed(() => title.value?.company)

useSeoMeta({
  title: () => title.value?.property_address ?? 'Candidate'
})

const stageChanging = ref(false)

const stageItems = computed<DropdownMenuItem[][]>(() => [
  (meta.value?.data.stages ?? []).map(stage => ({
    label: stage.label,
    icon: candidate.value?.stage === stage.value ? 'i-lucide-check' : undefined,
    onSelect: () => changeStage(stage.value)
  }))
])

async function changeStage(stage: PipelineStage) {
  if (!candidate.value || candidate.value.stage === stage) return

  stageChanging.value = true

  try {
    await api(`/candidates/${candidate.value.id}`, {
      method: 'PATCH',
      body: { stage }
    })

    // Refresh rather than patching locally: moving stage stamps a timestamp
    // server-side that the workflow card displays.
    await refresh()
    toast.add({ title: 'Stage updated', color: 'success' })
  } catch {
    toast.add({ title: 'Could not change the stage', color: 'error' })
  } finally {
    stageChanging.value = false
  }
}

/** Applies a PATCH response without a second round trip. */
function applyUpdate(updated: Candidate) {
  if (data.value) {
    // Notes and EPC certificates are not returned by PATCH, so keep ours.
    data.value = {
      data: {
        ...updated,
        title: updated.title ?? candidate.value?.title,
        notes: candidate.value?.notes
      }
    }
  }
}

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
  <UDashboardPanel id="candidate-detail">
    <template #header>
      <UDashboardNavbar :title="title?.title_number ?? 'Candidate'">
        <template #leading>
          <UButton
            to="/candidates"
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            aria-label="Back to candidates"
          />
        </template>

        <template #right>
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
        title="Could not load this candidate"
        :description="error.message"
      />

      <div
        v-else-if="candidate && title"
        class="space-y-6"
      >
        <!-- Header -->
        <UCard>
          <div class="flex flex-col lg:flex-row lg:items-start gap-4">
            <div class="flex items-start gap-4 min-w-0 flex-1">
              <UBadge
                :color="scoreColor(candidate.score)"
                variant="subtle"
                size="lg"
                class="tabular-nums text-lg shrink-0 mt-0.5"
              >
                {{ candidate.score }}
              </UBadge>

              <div class="min-w-0">
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
                    v-if="title.tenure === 'freehold'"
                    color="success"
                    variant="subtle"
                    size="sm"
                  >
                    Freehold
                  </UBadge>
                  <UBadge
                    v-if="title.multiple_address_indicator"
                    color="primary"
                    variant="subtle"
                    size="sm"
                  >
                    Multiple addresses
                  </UBadge>
                </div>
              </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
              <UBadge
                :color="stageColor(candidate.stage)"
                variant="subtle"
                size="lg"
              >
                {{ candidate.stage_label }}
              </UBadge>

              <UDropdownMenu :items="stageItems">
                <UButton
                  label="Change stage"
                  trailing-icon="i-lucide-chevron-down"
                  color="neutral"
                  variant="outline"
                  :loading="stageChanging"
                />
              </UDropdownMenu>
            </div>
          </div>
        </UCard>

        <!-- Key metrics -->
        <CandidateMetricsRow :candidate="candidate" />

        <!-- Research links get top billing: they are what turns a row in a
             table into a decision. -->
        <CandidateResearchLinks
          :title="title"
          :company="company"
        />

        <div class="grid gap-6 xl:grid-cols-3">
          <div class="xl:col-span-2 space-y-6">
            <CandidateScoreBreakdown
              :breakdown="candidate.score_breakdown"
              :score="candidate.score"
              :scored-at="candidate.scored_at"
            />

            <CandidatePropertyCard :title="title" />

            <CandidateNotesPanel :candidate="candidate" />
          </div>

          <div class="space-y-6">
            <CandidateDealEstimates
              :candidate="candidate"
              @saved="applyUpdate"
            />

            <CandidateWorkflowCard
              :candidate="candidate"
              @saved="applyUpdate"
            />

            <CandidateOwnershipCard
              :title="title"
              :company="company"
            />
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
