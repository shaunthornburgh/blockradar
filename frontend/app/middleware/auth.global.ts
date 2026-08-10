/**
 * Everything except the login page requires a Sanctum token.
 */
export default defineNuxtRouteMiddleware((to) => {
  const token = useAuthToken()

  if (to.path === '/login') {
    return token.value ? navigateTo('/') : undefined
  }

  if (!token.value) {
    return navigateTo('/login')
  }
})
