import type { Ref } from 'vue'

/**
 * Mirrors a ref, but only after it has been quiet for `delay` ms. Keeps the
 * filter inputs from firing a request per keystroke.
 */
export function useDebouncedRef<T>(source: Ref<T>, delay = 300): Ref<T> {
  const debounced = ref(source.value) as Ref<T>
  let timer: ReturnType<typeof setTimeout> | undefined

  watch(source, (value) => {
    clearTimeout(timer)
    timer = setTimeout(() => {
      debounced.value = value
    }, delay)
  })

  onScopeDispose(() => clearTimeout(timer))

  return debounced
}
