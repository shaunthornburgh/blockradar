<script setup lang="ts">
import type { Candidate, CandidateNote } from '~/types'

const props = defineProps<{ candidate: Candidate }>()

const api = useApi()
const toast = useToast()

const notes = ref<CandidateNote[]>([...(props.candidate.notes ?? [])])
const body = ref('')
const type = ref<CandidateNote['type']>('note')
const pending = ref(false)

watch(() => props.candidate.id, () => {
  notes.value = [...(props.candidate.notes ?? [])]
})

const typeItems = [
  { label: 'Note', value: 'note' },
  { label: 'Call', value: 'call' },
  { label: 'Letter', value: 'letter' },
  { label: 'Email', value: 'email' }
]

const TYPE_ICONS: Record<string, string> = {
  note: 'i-lucide-sticky-note',
  call: 'i-lucide-phone',
  letter: 'i-lucide-mail',
  email: 'i-lucide-at-sign'
}

async function add() {
  if (!body.value.trim()) return

  pending.value = true

  try {
    const response = await api<{ data: CandidateNote }>(`/candidates/${props.candidate.id}/notes`, {
      method: 'POST',
      body: { body: body.value.trim(), type: type.value }
    })

    // The relation is ordered newest first, so prepend to match.
    notes.value.unshift(response.data)
    body.value = ''
    toast.add({ title: 'Note added', color: 'success' })
  } catch {
    toast.add({ title: 'Could not add the note', color: 'error' })
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-center justify-between gap-3">
        <h2 class="font-semibold">
          Notes &amp; activity
        </h2>
        <UBadge
          color="neutral"
          variant="subtle"
        >
          {{ notes.length }}
        </UBadge>
      </div>
    </template>

    <form
      class="space-y-3 mb-4"
      @submit.prevent="add"
    >
      <UTextarea
        v-model="body"
        :rows="3"
        placeholder="What happened? Who did you speak to?"
        class="w-full"
      />

      <div class="flex items-center gap-2">
        <USelect
          v-model="type"
          :items="typeItems"
          value-key="value"
          class="w-32"
        />
        <UButton
          type="submit"
          label="Add note"
          icon="i-lucide-plus"
          :loading="pending"
          :disabled="!body.trim()"
        />
      </div>
    </form>

    <USeparator />

    <div
      v-if="!notes.length"
      class="py-6 text-center text-sm text-muted"
    >
      Nothing recorded yet.
    </div>

    <div
      v-else
      class="divide-y divide-default"
    >
      <div
        v-for="note in notes"
        :key="note.id"
        class="py-3 flex gap-3"
      >
        <UIcon
          :name="TYPE_ICONS[note.type] ?? 'i-lucide-sticky-note'"
          class="size-4 mt-0.5 text-muted shrink-0"
        />

        <div class="min-w-0 flex-1">
          <p class="text-sm whitespace-pre-line break-words">
            {{ note.body }}
          </p>
          <p class="text-xs text-dimmed mt-1">
            {{ note.user?.name ?? 'System' }} · {{ formatDate(note.created_at) }}
          </p>
        </div>
      </div>
    </div>
  </UCard>
</template>
