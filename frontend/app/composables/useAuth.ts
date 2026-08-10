import type { ApiResource, User } from '~/types'

interface LoginResponse {
  token: string
  user: ApiResource<User>['data']
}

export function useAuth() {
  const token = useAuthToken()
  // useState keeps the user shared across components and hydrated from SSR.
  const user = useState<User | null>('auth:user', () => null)

  const isAuthenticated = computed(() => Boolean(token.value))

  async function login(email: string, password: string): Promise<void> {
    const api = useApi()

    const response = await api<LoginResponse>('/login', {
      method: 'POST',
      body: { email, password, device_name: 'block-radar-web' }
    })

    token.value = response.token
    user.value = response.user
  }

  /** Loads the signed-in user, clearing the session if the token is stale. */
  async function fetchUser(): Promise<User | null> {
    if (!token.value) {
      user.value = null
      return null
    }

    try {
      const api = useApi()
      const response = await api<ApiResource<User>>('/user')
      user.value = response.data
    } catch {
      token.value = null
      user.value = null
    }

    return user.value
  }

  async function logout(): Promise<void> {
    if (token.value) {
      try {
        const api = useApi()
        await api('/logout', { method: 'POST' })
      } catch {
        // The token is being discarded either way.
      }
    }

    token.value = null
    user.value = null
    await navigateTo('/login')
  }

  return { user, token, isAuthenticated, login, logout, fetchUser }
}
