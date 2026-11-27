import { ref, watch, type WatchSource } from 'vue'
import { portalApi } from '@/api/portal'

interface AddressTarget {
  city: string | null
  state: string | null
  neighborhood?: string | null
}

/**
 * Preenche cidade, UF e bairro quando o CEP digitado fica completo (8 dígitos).
 * Cidade e UF seguem sempre o CEP. O bairro só é preenchido se estiver vazio ou se veio do
 * CEP anterior, porque o nome oficial às vezes difere do que a pessoa usa no dia a dia.
 */
export function useCepAutofill(cep: WatchSource<string | null | undefined>, target: AddressTarget) {
  const status = ref<'idle' | 'loading' | 'found' | 'not_found'>('idle')
  const message = ref('')
  let lastNeighborhood: string | null = null
  let lastCep = ''

  const replaceable = (current: string | null | undefined, previous: string | null | undefined) =>
    !current || (previous != null && current === previous)

  watch(cep, async (value) => {
    const digits = (value ?? '').replace(/\D/g, '')
    if (digits.length !== 8) {
      if (digits.length < 8) {
        status.value = 'idle'
        message.value = ''
      }
      lastCep = ''
      return
    }
    if (digits === lastCep) return
    lastCep = digits
    status.value = 'loading'
    message.value = 'Buscando endereço...'

    const address = await portalApi.cep(digits)
    if (digits !== lastCep) return // a pessoa já digitou outro CEP
    if (!address) {
      status.value = 'not_found'
      message.value = 'CEP não encontrado. Preencha a cidade e a UF.'
      return
    }
    target.city = address.city
    target.state = address.state
    if ('neighborhood' in target && address.neighborhood && replaceable(target.neighborhood, lastNeighborhood)) {
      target.neighborhood = address.neighborhood
    }
    lastNeighborhood = address.neighborhood
    status.value = 'found'
    message.value = [address.street, address.neighborhood, `${address.city}/${address.state}`].filter(Boolean).join(' · ')
  })

  /** Marca o CEP de um cadastro carregado como já conhecido (não busca nem sobrescreve nada). */
  function prime(value: string | null | undefined) {
    lastCep = (value ?? '').replace(/\D/g, '')
  }

  return { status, message, prime }
}
