<script setup lang="ts">
definePageMeta({ layout: 'auth' })
useSeoMeta({ title: 'Sign in' })

const config = useRuntimeConfig()
const { login } = useAuth()

const email = ref('admin@blockradar.test')
const password = ref('password')
const error = ref<string | null>(null)
const pending = ref(false)

async function onSubmit() {
  pending.value = true
  error.value = null

  try {
    await login(email.value, password.value)
    await navigateTo('/')
  } catch (e) {
    const status = (e as { statusCode?: number, status?: number }).statusCode
      ?? (e as { status?: number }).status

    error.value = status === 422 || status === 401
      ? 'Those credentials do not match our records.'
      : 'Could not reach the API. Is the backend running?'
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <UCard class="w-full max-w-sm">
    <template #header>
      <div class="flex items-center gap-2">
        <UIcon
          name="i-lucide-radar"
          class="size-6 text-primary"
        />
        <div>
          <h1 class="font-semibold">
            {{ config.public.appName }}
          </h1>
          <p class="text-sm text-muted">
            Sign in to review candidates
          </p>
        </div>
      </div>
    </template>

    <form
      class="space-y-4"
      @submit.prevent="onSubmit"
    >
      <UFormField
        label="Email"
        name="email"
      >
        <UInput
          v-model="email"
          type="email"
          autocomplete="email"
          placeholder="you@example.com"
          class="w-full"
        />
      </UFormField>

      <UFormField
        label="Password"
        name="password"
      >
        <UInput
          v-model="password"
          type="password"
          autocomplete="current-password"
          class="w-full"
        />
      </UFormField>

      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :description="error"
      />

      <UButton
        type="submit"
        block
        :loading="pending"
        label="Sign in"
      />
    </form>

    <template #footer>
      <p class="text-xs text-muted">
        Seeded account: <code>admin@blockradar.test</code> / <code>password</code>
      </p>
    </template>
  </UCard>
</template>
