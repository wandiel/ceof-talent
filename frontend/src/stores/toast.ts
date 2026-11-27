import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastType = 'success' | 'error' | 'info'

export interface Toast {
  id: number
  type: ToastType
  message: string
}

let nextId = 1

/** Notificações de feedback das operações (canto superior direito). */
export const useToastStore = defineStore('toast', () => {
  const toasts = ref<Toast[]>([])

  function push(type: ToastType, message: string, timeout = 5000) {
    const id = nextId++
    toasts.value.push({ id, type, message })
    window.setTimeout(() => dismiss(id), timeout)
  }

  function dismiss(id: number) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  return {
    toasts,
    dismiss,
    success: (message: string) => push('success', message),
    error: (message: string) => push('error', message, 8000),
    info: (message: string) => push('info', message),
  }
})
