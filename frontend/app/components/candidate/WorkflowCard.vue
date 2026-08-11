<script setup lang="ts">
import type { Candidate, User } from '~/types'

const props = defineProps<{ candidate: Candidate }>()
const emit = defineEmits<{ saved: [Candidate] }>()

const api = useApi()
const toast = useToast()

const { data: users } = await useAsyncData(
  'users',
  () => api<{ data: User[] }>('/users'),
  { default: () => ({ data: [] as User[] }) }
)

const assigneeItems = computed(() => [
  { label: 'Unassigned', value: null as number | null },
  ...(users.value?.data ?? []).map(user => ({ label: user.name, value: user.id as number | null }))
])

const assignee = ref<number | null>(props.candidate.assigned_to?.id ?? null)
// UInput binds a string; null would not satisfy its model type.
const nextActionAt = ref<string>(props.candidate.next_action_at ?? '')
const pending = ref(false)

watch(() => props.candidate, (candidate) => {
  assignee.value = candidate.assigned_to?.id ?? null
  nextActionAt.value = candidate.next_action_at ?? ''
})

const dirty = computed(() =>
  assignee.value !== (props.candidate.assigned_to?.id ?? null)
  || nextActionAt.value !== (props.candidate.next_action_at ?? '')
)

async function save() {
  pending.value = true

  try {
    const response = await api<{ data: Candidate }>(`/candidates/${props.candidate.id}`, {
      method: 'PATCH',
      body: {
        assigned_to_id: assignee.value,
        next_action_at: nextActionAt.value || null
      }
    })

    emit('saved', response.data)
    toast.add({ title: 'Workflow updated', color: 'success' })
  } catch {
    toast.add({ title: 'Could not update the workflow', color: 'error' })
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <UCard>
    <template #header>
      <h2 class="font-semibold">
        Workflow
      </h2>
    </template>

    <div class="space-y-4">
      <UFormField
        label="Assignee"
        name="assigned_to_id"
      >
        <USelect
          v-model="assignee"
          :items="assigneeItems"
          value-key="value"
          class="w-full"
        />
      </UFormField>

      <UFormField
        label="Next action"
        name="next_action_at"
      >
        <UInput
          v-model="nextActionAt"
          type="date"
          class="w-full"
        />
      </UFormField>

      <UButton
        label="Save"
        :loading="pending"
        :disabled="!dirty"
        block
        @click="save"
      />

      <USeparator />

      <dl class="space-y-2 text-sm">
        <div class="flex justify-between gap-3">
          <dt class="text-muted">
            Added to pipeline
          </dt>
          <dd>{{ formatDate(candidate.created_at) }}</dd>
        </div>
        <div
          v-for="entry in [
            { label: 'Title bought', value: candidate.title_bought_at },
            { label: 'Confirmed', value: candidate.confirmed_at },
            { label: 'Outreach started', value: candidate.outreach_at },
            { label: 'Offer made', value: candidate.offered_at }
          ].filter(e => e.value)"
          :key="entry.label"
          class="flex justify-between gap-3"
        >
          <dt class="text-muted">
            {{ entry.label }}
          </dt>
          <dd>{{ formatDate(entry.value) }}</dd>
        </div>
      </dl>
    </div>
  </UCard>
</template>
