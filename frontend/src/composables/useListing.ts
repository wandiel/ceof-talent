import { onMounted, reactive, ref, watch } from 'vue'
import { parseApiError } from '@/api/http'
import type { ListParams, Paginated } from '@/api/types'
import { useToastStore } from '@/stores/toast'

/**
 * Estado e carregamento de uma listagem paginada com busca, filtro de situação,
 * ordenação e filtros extras. A busca é aplicada com atraso (debounce).
 */
export function useListing<T>(fetcher: (params: ListParams) => Promise<Paginated<T>>, defaults: ListParams = {}) {
  const toast = useToastStore()
  const params = reactive<ListParams>({ search: '', status: '', sort: 'name', page: 1, per_page: 15, ...defaults })
  const items = ref<T[]>([]) as { value: T[] }
  const meta = ref<Paginated<T>['meta']>({ current_page: 1, last_page: 1, per_page: 15, total: 0, from: null, to: null })
  const loading = ref(false)
  let timer: number | undefined
  let requestId = 0

  async function load() {
    const current = ++requestId
    loading.value = true
    try {
      const result = await fetcher({ ...params })
      if (current !== requestId) return
      items.value = result.data
      meta.value = result.meta
    } catch (error) {
      toast.error(parseApiError(error).message)
    } finally {
      if (current === requestId) loading.value = false
    }
  }

  watch(
    () => params.search,
    () => {
      window.clearTimeout(timer)
      timer = window.setTimeout(() => {
        params.page = 1
        load()
      }, 300)
    },
  )

  watch(
    () => Object.entries(params).filter(([k]) => k !== 'search' && k !== 'page').map(([, v]) => v).join('|'),
    () => {
      params.page = 1
      load()
    },
  )

  function goTo(page: number) {
    params.page = page
    load()
  }

  onMounted(load)

  return { params, items, meta, loading, load, goTo }
}
