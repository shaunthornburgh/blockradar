<script setup lang="ts">
import type { Candidate } from '~/types'

const props = defineProps<{ candidate: Candidate }>()
const emit = defineEmits<{ saved: [Candidate] }>()

const api = useApi()
const toast = useToast()

/**
 * Money crosses the API in pence; these inputs work in pounds. Conversion
 * happens only at this boundary.
 */
const form = reactive({
  estimated_units: props.candidate.estimated_units,
  estimated_gdv: toPounds(props.candidate.estimated_gdv),
  estimated_uplift: toPounds(props.candidate.estimated_uplift),
  gross_yield: props.candidate.gross_yield
})

const pending = ref(false)

const dirty = computed(() =>
  form.estimated_units !== props.candidate.estimated_units
  || form.estimated_gdv !== toPounds(props.candidate.estimated_gdv)
  || form.estimated_uplift !== toPounds(props.candidate.estimated_uplift)
  || form.gross_yield !== props.candidate.gross_yield
)

function toPounds(pence: number | null): number | null {
  return pence === null ? null : Math.round(pence / 100)
}

function toPence(pounds: number | null): number | null {
  return pounds === null || Number.isNaN(pounds) ? null : Math.round(pounds * 100)
}

function reset() {
  form.estimated_units = props.candidate.estimated_units
  form.estimated_gdv = toPounds(props.candidate.estimated_gdv)
  form.estimated_uplift = toPounds(props.candidate.estimated_uplift)
  form.gross_yield = props.candidate.gross_yield
}

// A rescore or stage change replaces the candidate; keep the form in step
// unless the user is mid-edit.
watch(() => props.candidate, () => {
  if (!dirty.value) reset()
})

async function save() {
  pending.value = true

  try {
    const response = await api<{ data: Candidate }>(`/candidates/${props.candidate.id}`, {
      method: 'PATCH',
      body: {
        estimated_units: form.estimated_units,
        estimated_gdv: toPence(form.estimated_gdv),
        estimated_uplift: toPence(form.estimated_uplift),
        gross_yield: form.gross_yield
      }
    })

    emit('saved', response.data)
    toast.add({ title: 'Estimates saved', color: 'success' })
  } catch {
    toast.add({ title: 'Could not save the estimates', color: 'error' })
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-center justify-between gap-3">
        <div>
          <h2 class="font-semibold">
            Deal estimates
          </h2>
          <p class="text-xs text-muted mt-0.5">
            Your figures — never overwritten by scoring or enrichment
          </p>
        </div>
        <UIcon
          name="i-lucide-calculator"
          class="size-5 text-muted"
        />
      </div>
    </template>

    <form
      class="space-y-4"
      @submit.prevent="save"
    >
      <div class="grid gap-4 sm:grid-cols-2">
        <UFormField
          label="Estimated units"
          name="estimated_units"
        >
          <UInputNumber
            v-model="form.estimated_units"
            :min="1"
            :max="2000"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Gross yield"
          name="gross_yield"
        >
          <UInputNumber
            v-model="form.gross_yield"
            :min="0"
            :max="100"
            :step="0.1"
            :format-options="{ style: 'percent', maximumFractionDigits: 2 }"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Estimated GDV"
          name="estimated_gdv"
          hint="£"
        >
          <UInputNumber
            v-model="form.estimated_gdv"
            :min="0"
            :step="1000"
            :format-options="{ style: 'currency', currency: 'GBP', maximumFractionDigits: 0 }"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Estimated uplift"
          name="estimated_uplift"
          hint="£"
        >
          <UInputNumber
            v-model="form.estimated_uplift"
            :step="1000"
            :format-options="{ style: 'currency', currency: 'GBP', maximumFractionDigits: 0 }"
            class="w-full"
          />
        </UFormField>
      </div>

      <div class="flex items-center gap-2">
        <UButton
          type="submit"
          label="Save estimates"
          :loading="pending"
          :disabled="!dirty"
        />
        <UButton
          v-if="dirty"
          label="Reset"
          color="neutral"
          variant="ghost"
          @click="reset"
        />
      </div>
    </form>
  </UCard>
</template>
