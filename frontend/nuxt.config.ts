// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui'
  ],

  devtools: {
    enabled: true
  },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    // Server-side rendering talks to nginx over the compose network.
    // Overridden by NUXT_API_INTERNAL_BASE.
    apiInternalBase: 'http://nginx/api',

    public: {
      // The browser reaches the API through the published port.
      // Overridden by NUXT_PUBLIC_API_BASE.
      apiBase: 'http://localhost:8000/api',
      appName: 'Block Radar'
    }
  },

  // Reachable from outside the container.
  devServer: {
    host: '0.0.0.0',
    port: 3000
  },

  compatibilityDate: '2026-06-30',

  vite: {
    server: {
      watch: {
        // Bind mounts on macOS and Windows do not deliver inotify events.
        usePolling: true,
        interval: 300
      }
    }
  },

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  },

  // Icons are bundled rather than fetched. Without this the server-side render
  // tries a relative fetch to the icon endpoint, which cannot resolve during
  // SSR, so icons only appear after hydration.
  icon: {
    serverBundle: 'local',
    clientBundle: {
      scan: true,
      // Listed explicitly because these are referenced through variables
      // (navigation items, stat cards) and static scanning cannot see them.
      icons: [
        'lucide:radar',
        'lucide:layout-dashboard',
        'lucide:target',
        'lucide:scroll-text',
        'lucide:building-2',
        'lucide:circle-user',
        'lucide:chevrons-up-down',
        'lucide:log-out',
        'lucide:refresh-cw',
        'lucide:search',
        'lucide:chevron-down',
        'lucide:triangle-alert',
        'lucide:flame',
        'lucide:layers',
        'lucide:arrow-right',
        'lucide:check'
      ]
    }
  }
})
