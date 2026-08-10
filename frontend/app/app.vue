<script setup lang="ts">
const config = useRuntimeConfig()
const { fetchUser } = useAuth()

useHead({
  meta: [
    { name: 'viewport', content: 'width=device-width, initial-scale=1' }
  ],
  link: [
    { rel: 'icon', href: '/favicon.ico' }
  ],
  htmlAttrs: {
    lang: 'en-GB'
  }
})

useSeoMeta({
  titleTemplate: `%s · ${config.public.appName}`,
  title: config.public.appName,
  description: 'Deal sourcing for unsplit multi-unit freehold blocks in England & Wales.'
})

// Resolve the signed-in user once during SSR so the sidebar renders complete.
await useAsyncData('auth:user', () => fetchUser())
</script>

<template>
  <UApp>
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
