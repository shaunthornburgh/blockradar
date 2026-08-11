<script setup lang="ts">
import type { Company, Title } from '~/types'

const props = defineProps<{
  title: Title
  company?: Company
}>()

const { groups } = useResearchLinks(
  computed(() => props.title),
  computed(() => props.company)
)
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-center justify-between gap-3">
        <div>
          <h2 class="font-semibold">
            Research
          </h2>
          <p class="text-xs text-muted mt-0.5">
            Opens in a new tab
          </p>
        </div>
        <UIcon
          name="i-lucide-compass"
          class="size-5 text-muted"
        />
      </div>
    </template>

    <div class="space-y-5">
      <div
        v-for="group in groups"
        :key="group.title"
      >
        <div class="flex items-center gap-2 mb-2">
          <UIcon
            :name="group.icon"
            class="size-4 text-muted"
          />
          <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">
            {{ group.title }}
          </h3>
        </div>

        <div class="grid gap-2 sm:grid-cols-2">
          <UButton
            v-for="link in group.links"
            :key="link.label"
            :to="link.href"
            target="_blank"
            rel="noopener noreferrer"
            color="neutral"
            variant="outline"
            class="items-start text-left h-auto py-2.5"
            block
          >
            <UIcon
              :name="link.icon"
              class="size-4 shrink-0 mt-0.5 text-primary"
            />
            <span class="min-w-0 flex-1">
              <span class="flex items-center gap-1 font-medium">
                {{ link.label }}
                <UIcon
                  name="i-lucide-external-link"
                  class="size-3 text-dimmed"
                />
              </span>
              <span class="block text-xs text-muted font-normal mt-0.5">
                {{ link.description }}
              </span>
              <span
                v-if="link.caveat"
                class="block text-xs text-dimmed font-normal mt-1 italic"
              >
                {{ link.caveat }}
              </span>
            </span>
          </UButton>
        </div>
      </div>
    </div>
  </UCard>
</template>
