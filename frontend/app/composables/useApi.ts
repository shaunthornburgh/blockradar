/**
 * The bearer token issued by Laravel Sanctum. Held in a cookie so that
 * server-rendered requests can authenticate too.
 */
export function useAuthToken() {
  return useCookie<string | null>('block_radar_token', {
    default: () => null,
    sameSite: 'lax',
    maxAge: 60 * 60 * 24 * 30,
    // The Nuxt server needs to read it, so it cannot be httpOnly.
    secure: import.meta.env.PROD
  })
}

/**
 * A $fetch instance pointed at the Laravel API, with the bearer token attached
 * and 401s bounced to the login page.
 *
 * SSR uses the internal compose hostname; the browser uses the published port.
 */
export function useApi() {
  const config = useRuntimeConfig()
  const token = useAuthToken()

  const baseURL = import.meta.server
    ? config.apiInternalBase
    : config.public.apiBase

  return $fetch.create({
    baseURL,
    headers: {
      Accept: 'application/json'
    },
    onRequest({ options }) {
      if (!token.value) {
        return
      }

      // Normalised because ofetch accepts several header shapes.
      const headers = new Headers(options.headers)
      headers.set('Authorization', `Bearer ${token.value}`)
      options.headers = headers
    },
    async onResponseError({ response }) {
      if (response.status !== 401) {
        return
      }

      token.value = null

      // Avoid a redirect loop when the login request itself is rejected.
      const route = useRoute()
      if (route.path !== '/login') {
        await navigateTo('/login')
      }
    }
  })
}
