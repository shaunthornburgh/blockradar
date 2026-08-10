<script setup lang="ts">
import type { ApiResource, DashboardSummary } from '~/types'

useSeoMeta({ title: 'Dashboard' })

const api = useApi()

const { data, pending, error, refresh } = await useAsyncData(
  'dashboard',
  () => api<ApiResource<DashboardSummary>>('/dashboard')
)

const summary = computed(() => data.value?.data)

const stats = computed(() => {
  const totals = summary.value?.totals

  return [
    {
      label: 'Split candidates',
      hint: 'Freehold + multiple address',
      value: formatNumber(totals?.split_candidates),
      icon: 'i-lucide-layers'
    },
    {
      label: 'In pipeline',
      hint: 'Not archived',
      value: formatNumber(totals?.candidates),
      icon: 'i-lucide-target'
    },
    {
      label: 'High priority',
      hint: 'Score 70 or above',
      value: formatNumber(totals?.high_score_candidates),
      icon: 'i-lucide-flame'
    },
    {
      label: 'Awaiting enrichment',
      hint: 'No Companies House pull yet',
      value: formatNumber(totals?.companies_awaiting_enrichment),
      icon: 'i-lucide-building-2'
    }
  ]
})

const pipelineTotal = computed(
  () => summary.value?.pipeline.reduce((sum, stage) => sum + stage.count, 0) ?? 0
)
</script>

<template>
  <UDashboardPanel id="dashboard">
    <template #header>
      <UDashboardNavbar title="Dashboard">
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
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Could not load the dashboard"
        :description="error.message"
        class="mb-6"
      />

      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
        <UCard
          v-for="stat in stats"
          :key="stat.label"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-sm text-muted truncate">
                {{ stat.label }}
              </p>
              <p class="text-2xl font-semibold tabular-nums mt-1">
                {{ stat.value }}
              </p>
              <p class="text-xs text-dimmed mt-1 truncate">
                {{ stat.hint }}
              </p>
            </div>
            <UIcon
              :name="stat.icon"
              class="size-5 text-muted shrink-0"
            />
          </div>
        </UCard>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <UCard class="lg:col-span-1">
          <template #header>
            <div class="flex items-center justify-between">
              <h2 class="font-semibold">
                Pipeline
              </h2>
              <UBadge
                color="neutral"
                variant="subtle"
              >
                {{ formatNumber(pipelineTotal) }}
              </UBadge>
            </div>
          </template>

          <div class="space-y-3">
            <div
              v-for="stage in summary?.pipeline"
              :key="stage.stage"
              class="space-y-1"
            >
              <div class="flex items-center justify-between text-sm">
                <span>{{ stage.label }}</span>
                <span class="tabular-nums text-muted">{{ formatNumber(stage.count) }}</span>
              </div>
              <UProgress
                :model-value="pipelineTotal ? (stage.count / pipelineTotal) * 100 : 0"
                :color="stageColor(stage.stage)"
                size="sm"
              />
            </div>
          </div>
        </UCard>

        <UCard class="lg:col-span-2">
          <template #header>
            <div class="flex items-center justify-between">
              <h2 class="font-semibold">
                Top scoring candidates
              </h2>
              <UButton
                to="/candidates"
                label="View all"
                trailing-icon="i-lucide-arrow-right"
                color="neutral"
                variant="ghost"
                size="xs"
              />
            </div>
          </template>

          <div class="divide-y divide-default">
            <div
              v-for="candidate in summary?.top_candidates"
              :key="candidate.id"
              class="py-3 first:pt-0 last:pb-0 flex items-center gap-3"
            >
              <UBadge
                :color="scoreColor(candidate.score)"
                variant="subtle"
                class="tabular-nums shrink-0"
              >
                {{ candidate.score }}
              </UBadge>

              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium truncate">
                  {{ candidate.title?.property_address }}
                </p>
                <p class="text-xs text-muted truncate">
                  {{ candidate.title?.title_number }}
                  · {{ candidate.title?.region }}
                  · {{ candidate.estimated_units ?? '?' }} units
                </p>
              </div>

              <UBadge
                :color="stageColor(candidate.stage)"
                variant="subtle"
                class="shrink-0 hidden sm:inline-flex"
              >
                {{ candidate.stage_label }}
              </UBadge>
            </div>

            <p
              v-if="!summary?.top_candidates?.length"
              class="py-6 text-sm text-muted text-center"
            >
              No candidates yet. Run the CCOD import to populate the pipeline.
            </p>
          </div>
        </UCard>
      </div>

      <UCard
        v-if="summary?.latest_import"
        class="mt-6"
      >
        <template #header>
          <h2 class="font-semibold">
            Latest CCOD import
          </h2>
        </template>

        <dl class="grid gap-4 sm:grid-cols-4 text-sm">
          <div>
            <dt class="text-muted">
              File
            </dt>
            <dd class="font-medium truncate">
              {{ summary.latest_import.filename }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Status
            </dt>
            <dd>
              <UBadge
                :color="summary.latest_import.status === 'completed' ? 'success' : 'neutral'"
                variant="subtle"
              >
                {{ summary.latest_import.status }}
              </UBadge>
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Rows imported
            </dt>
            <dd class="font-medium tabular-nums">
              {{ formatNumber(summary.latest_import.rows_imported) }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Finished
            </dt>
            <dd class="font-medium">
              {{ formatDate(summary.latest_import.finished_at) }}
            </dd>
          </div>
        </dl>
      </UCard>
    </template>
  </UDashboardPanel>
</template>
