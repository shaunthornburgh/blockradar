<script setup lang="ts">
import type { CandidateSummary, TitlePipelineStatus } from '~/types'

/**
 * Whether this title reached the MUFB pipeline, and what to do about it.
 *
 * Read-only by design. Stage changes, notes and deal estimates belong on the
 * candidate page; this card's job is to say where the title stands and link
 * across when there is somewhere to link to.
 */
const props = defineProps<{
  pipeline: TitlePipelineStatus
  candidate?: CandidateSummary | null
}>()

const mufbColor = computed(() => {
  switch (props.candidate?.mufb.level) {
    case 'high': return 'success'
    case 'medium': return 'warning'
    default: return 'neutral'
  }
})
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-center justify-between gap-3">
        <h2 class="font-semibold">
          MUFB pipeline
        </h2>
        <UIcon
          :name="candidate ? 'i-lucide-target' : 'i-lucide-circle-slash'"
          class="size-5 text-muted"
        />
      </div>
    </template>

    <!-- In the pipeline -->
    <div
      v-if="candidate"
      class="space-y-4"
    >
      <div class="flex flex-wrap items-center gap-2">
        <UBadge
          :color="stageColor(candidate.stage)"
          variant="subtle"
          size="lg"
        >
          {{ candidate.stage_label }}
        </UBadge>

        <UBadge
          :color="scoreColor(candidate.score)"
          variant="subtle"
          size="lg"
          class="tabular-nums"
        >
          Score {{ candidate.score }}
        </UBadge>

        <UBadge
          :color="mufbColor"
          variant="subtle"
          size="lg"
          class="tabular-nums"
        >
          MUFB {{ candidate.mufb.confidence }}
        </UBadge>

        <UBadge
          v-if="candidate.is_archived"
          color="neutral"
          variant="subtle"
          size="lg"
        >
          Archived
        </UBadge>
      </div>

      <div
        v-if="candidate.mufb.signals.length"
        class="flex flex-wrap gap-1.5"
      >
        <UBadge
          v-for="signal in candidate.mufb.signals"
          :key="signal"
          color="neutral"
          variant="subtle"
          size="sm"
        >
          {{ signal }}
        </UBadge>
      </div>

      <p class="text-xs text-muted">
        Added to the pipeline {{ formatDate(candidate.created_at) }}.
        <template v-if="candidate.scored_at">
          Last scored {{ formatDate(candidate.scored_at) }}.
        </template>
      </p>

      <UButton
        :to="`/candidates/${candidate.id}`"
        label="Open the candidate"
        icon="i-lucide-target"
        trailing-icon="i-lucide-arrow-right"
        color="primary"
        block
      />

      <p class="text-xs text-dimmed">
        Stage changes, notes and deal estimates live on the candidate page.
        This one is read-only.
      </p>
    </div>

    <!-- Filtered out, with a reason we can actually stand behind -->
    <div
      v-else-if="pipeline.reason_label"
      class="space-y-3"
    >
      <UAlert
        color="neutral"
        variant="subtle"
        icon="i-lucide-filter-x"
        title="Not in the MUFB pipeline"
        :description="pipeline.reason_label"
      />

      <p class="text-xs text-dimmed">
        Judged against the filter as it is configured now, which is not
        necessarily the one that ran when this title was imported.
      </p>
    </div>

    <!-- Passes the filter, but no candidate row exists -->
    <div
      v-else
      class="space-y-3"
    >
      <UAlert
        color="warning"
        variant="subtle"
        icon="i-lucide-circle-help"
        title="Meets the filter, but is not a candidate"
        description="Candidates are created during a CCOD import. This title may have been imported under a narrower filter than the one configured now, or its candidate may have been removed since."
      />

      <p class="text-xs text-dimmed">
        The next import will promote it if it still qualifies.
      </p>
    </div>
  </UCard>
</template>
