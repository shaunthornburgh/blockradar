<script setup lang="ts">
import type { NavigationMenuItem, DropdownMenuItem } from '@nuxt/ui'

const config = useRuntimeConfig()
const { user, logout } = useAuth()

const links: NavigationMenuItem[][] = [[
  { label: 'Dashboard', icon: 'i-lucide-layout-dashboard', to: '/' },
  { label: 'Candidates', icon: 'i-lucide-target', to: '/candidates' },
  { label: 'Titles', icon: 'i-lucide-scroll-text', to: '/titles' },
  { label: 'Companies', icon: 'i-lucide-building-2', to: '/companies' }
]]

const userMenu = computed<DropdownMenuItem[][]>(() => [[
  {
    label: user.value?.name ?? 'Signed in',
    avatar: { alt: user.value?.name ?? 'BR' },
    type: 'label'
  }
], [
  {
    label: 'Sign out',
    icon: 'i-lucide-log-out',
    onSelect: () => logout()
  }
]])
</script>

<template>
  <UDashboardGroup
    storage="local"
    storage-key="block-radar-dashboard"
  >
    <UDashboardSidebar
      id="block-radar-sidebar"
      collapsible
      resizable
      :default-size="16"
      :min-size="12"
      :max-size="24"
    >
      <template #header="{ collapsed }">
        <div class="flex items-center gap-2 min-w-0">
          <UIcon
            name="i-lucide-radar"
            class="size-6 text-primary shrink-0"
          />
          <span
            v-if="!collapsed"
            class="font-semibold truncate"
          >
            {{ config.public.appName }}
          </span>
        </div>
      </template>

      <template #default="{ collapsed }">
        <UNavigationMenu
          orientation="vertical"
          :collapsed="collapsed"
          :items="links"
        />
      </template>

      <template #footer="{ collapsed }">
        <UDropdownMenu
          :items="userMenu"
          class="w-full"
        >
          <UButton
            :label="collapsed ? undefined : (user?.name ?? 'Account')"
            :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
            icon="i-lucide-circle-user"
            color="neutral"
            variant="ghost"
            block
            :square="collapsed"
            class="justify-start"
          />
        </UDropdownMenu>
      </template>
    </UDashboardSidebar>

    <slot />
  </UDashboardGroup>
</template>
