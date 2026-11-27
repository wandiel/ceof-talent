<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { parseApiError } from '@/api/http'
import { privacyApi, type CandidatePrivacy } from '@/api/privacy'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'
import { formatDate, formatDateTime } from '@/utils/format'

const props = defineProps<{ candidateId: string; anonymized: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const toast = useToastStore()

const data = ref<CandidatePrivacy | null>(null)
const busy = ref(false)
const dialog = ref<'consent' | 'revoke' | 'anonymize' | null>(null)
const errors = ref<Record<string, string>>({})
const form = reactive({ purpose: 'talent_pool', evidence: '', reason: '', confirm: '', request: '' as string, consent: '' })
const exporting = ref(false)

async function load() {
  try {
    data.value = await privacyApi.candidate(props.candidateId)
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
}
onMounted(load)

function open(kind: 'consent' | 'revoke' | 'anonymize', consent = '') {
  Object.assign(form, { purpose: 'talent_pool', evidence: '', reason: '', confirm: '', request: '', consent })
  errors.value = {}
  dialog.value = kind
}

async function submit() {
  busy.value = true
  errors.value = {}
  try {
    if (dialog.value === 'consent') {
      await privacyApi.addConsent(props.candidateId, { purpose: form.purpose, evidence: form.evidence })
      toast.success('Consentimento registrado.')
    } else if (dialog.value === 'revoke') {
      await privacyApi.revokeConsent(props.candidateId, form.consent, form.reason)
      toast.success('Consentimento revogado.')
    } else if (dialog.value === 'anonymize') {
      await privacyApi.anonymize(props.candidateId, { reason: form.reason, confirm: form.confirm, request: form.request || null })
      toast.success('Cadastro anonimizado.')
    }
    dialog.value = null
    await load()
    emit('changed')
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.fields
    if (!Object.keys(parsed.fields).length || parsed.fields.candidate) toast.error(parsed.message)
  } finally {
    busy.value = false
  }
}

async function exportData() {
  exporting.value = true
  try {
    await privacyApi.exportData(props.candidateId)
    toast.success('Relatório de dados gerado. Confira a pasta de downloads.')
    emit('changed')
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    exporting.value = false
  }
}

const payloadLabels: Record<string, string> = {
  full_name: 'Nome', social_name: 'Nome social', email: 'E-mail', phone: 'Telefone', birth_date: 'Nascimento', city: 'Cidade', state: 'UF',
  professional_area: 'Área', education_level: 'Escolaridade', desired_position: 'Cargo pretendido', summary: 'Resumo', linkedin_url: 'LinkedIn', cpf_informed: 'Informou CPF',
}
const show = (v: unknown) => (v === true ? 'Sim' : v === false ? 'Não' : String(v ?? '—'))
const titles = { consent: 'Registrar consentimento', revoke: 'Revogar consentimento', anonymize: 'Anonimizar cadastro' }
</script>

<template>
  <div v-if="!data" class="h-48 animate-pulse rounded-2xl bg-slate-100" />
  <div v-else class="grid gap-4 lg:grid-cols-3">
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold text-slate-900">Consentimentos</h2>
        <BaseButton v-if="!anonymized" size="sm" variant="secondary" @click="open('consent')">Registrar consentimento</BaseButton>
      </div>
      <ul v-if="data.consents.length" class="mt-3 divide-y divide-slate-100">
        <li v-for="c in data.consents" :key="c.id" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
          <div>
            <p class="font-medium text-slate-900">
              {{ c.purpose_label }}
              <span v-if="c.revoked_at" class="ml-1 rounded bg-slate-100 px-1.5 text-xs font-normal text-slate-600">revogado</span>
              <span v-else class="ml-1 rounded bg-emerald-50 px-1.5 text-xs font-normal text-emerald-800">ativo</span>
            </p>
            <p class="text-xs text-slate-500">
              {{ formatDateTime(c.granted_at) }} · {{ c.channel_label }}<span v-if="c.recorded_by"> por {{ c.recorded_by }}</span> · aviso v{{ c.version }}<span v-if="c.ip_address"> · IP {{ c.ip_address }}</span>
            </p>
            <p v-if="c.revoked_at" class="text-xs text-slate-500">Revogado em {{ formatDateTime(c.revoked_at) }}: {{ c.revoked_reason }}</p>
          </div>
          <button v-if="!c.revoked_at && !anonymized" type="button" class="text-xs font-medium text-red-700 hover:underline" @click="open('revoke', c.id)">Revogar</button>
        </li>
      </ul>
      <p v-else class="mt-3 text-sm text-slate-500">Nenhum consentimento registrado. Cadastros feitos pelo RH usam o legítimo interesse; registre o consentimento quando o candidato autorizar o banco de talentos.</p>

      <h2 class="mt-6 font-semibold text-slate-900">Envios pelo portal</h2>
      <details v-for="s in data.submissions" :key="s.protocol" class="mt-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
        <summary class="cursor-pointer">
          <span class="font-mono font-medium">{{ s.protocol }}</span> · {{ formatDateTime(s.created_at) }} · {{ s.job ?? 'Banco de talentos' }}
          <span v-if="!s.is_new_candidate" class="text-xs text-amber-700">(cadastro já existia)</span>
        </summary>
        <dl class="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-2">
          <template v-for="(v, k) in s.payload" :key="k">
            <div v-if="payloadLabels[k]" class="flex gap-2"><dt class="text-slate-500">{{ payloadLabels[k] }}:</dt><dd class="text-slate-800">{{ show(v) }}</dd></div>
          </template>
        </dl>
      </details>
      <p v-if="!data.submissions.length" class="mt-2 text-sm text-slate-500">Nenhum envio pelo portal.</p>
    </section>

    <aside class="space-y-4">
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-900">Retenção</h2>
        <p v-if="anonymized" class="mt-2 text-sm text-slate-600">Cadastro anonimizado em {{ formatDate(data.anonymized_at) }}.</p>
        <template v-else>
          <p v-if="data.retention.has_open_process" class="mt-2 text-sm text-slate-600">Em processo seletivo ou contratado: não entra na anonimização automática.</p>
          <p v-else-if="data.retention.expires_at" class="mt-2 text-sm text-slate-600">
            Pela política de retenção, este cadastro será anonimizado em <strong>{{ formatDate(data.retention.expires_at) }}</strong>, se não houver nova atividade.
          </p>
        </template>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-900">Direitos do titular</h2>
        <ul v-if="data.requests.length" class="mt-2 space-y-1 text-sm">
          <li v-for="r in data.requests" :key="r.id">
            <RouterLink :to="{ path: '/lgpd', query: { aba: 'solicitacoes', pedido: r.id } }" class="text-brand-700 hover:underline">{{ r.protocol }}</RouterLink>
            · {{ r.type_label }} · {{ r.status_label }}
          </li>
        </ul>
        <p v-else class="mt-2 text-sm text-slate-500">Nenhuma solicitação registrada.</p>
        <div v-if="!anonymized" class="mt-4 flex flex-col gap-2">
          <BaseButton variant="secondary" :loading="exporting" @click="exportData">Exportar dados do titular (JSON)</BaseButton>
          <BaseButton variant="danger" @click="open('anonymize')">Anonimizar cadastro</BaseButton>
        </div>
      </section>
    </aside>

    <BaseModal :open="dialog !== null" :title="dialog ? titles[dialog] : ''" @close="dialog = null">
      <form id="privacy-dialog" class="space-y-4" @submit.prevent="submit">
        <template v-if="dialog === 'consent'">
          <FormField label="Finalidade" for="pv-purpose" required>
            <select id="pv-purpose" v-model="form.purpose" :class="inputClass(errors.purpose)">
              <option v-for="p in data.purposes" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
          </FormField>
          <FormField label="Como foi obtido" for="pv-evidence" required hint="Ex.: termo assinado na recepção em 10/12, e-mail do candidato." :error="errors.evidence">
            <input id="pv-evidence" v-model="form.evidence" :class="inputClass(errors.evidence)" maxlength="255" />
          </FormField>
          <p class="text-xs text-slate-500">Fica registrado com a versão atual do aviso de privacidade (v{{ data.notice_version }}).</p>
        </template>
        <template v-else-if="dialog === 'revoke'">
          <FormField label="Motivo" for="pv-reason" required hint="Ex.: pedido do titular por e-mail." :error="errors.reason">
            <input id="pv-reason" v-model="form.reason" :class="inputClass(errors.reason)" maxlength="255" />
          </FormField>
        </template>
        <template v-else-if="dialog === 'anonymize'">
          <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-900">
            <strong>Esta ação não pode ser desfeita.</strong> Nome, contatos, CPF, endereço, formação, experiências, anotações, documentos e arquivos serão apagados.
            Ficam apenas dados estatísticos (área, escolaridade, cidade, etapas dos processos).
          </div>
          <FormField label="Motivo" for="pv-reason" required hint="Ex.: pedido de eliminação do titular." :error="errors.reason">
            <input id="pv-reason" v-model="form.reason" :class="inputClass(errors.reason)" maxlength="255" />
          </FormField>
          <FormField v-if="data.requests.length" label="Solicitação relacionada" for="pv-request">
            <select id="pv-request" v-model="form.request" :class="inputClass()">
              <option value="">Nenhuma</option>
              <option v-for="r in data.requests" :key="r.id" :value="r.id">{{ r.protocol }} · {{ r.type_label }}</option>
            </select>
          </FormField>
          <FormField label="Digite ANONIMIZAR para confirmar" for="pv-confirm" required :error="errors.confirm">
            <input id="pv-confirm" v-model="form.confirm" autocomplete="off" :class="inputClass(errors.confirm)" />
          </FormField>
        </template>
      </form>
      <template #footer>
        <BaseButton variant="secondary" @click="dialog = null">Cancelar</BaseButton>
        <BaseButton type="submit" form="privacy-dialog" :variant="dialog === 'consent' ? 'primary' : 'danger'" :loading="busy" :disabled="dialog === 'anonymize' && form.confirm !== 'ANONIMIZAR'">
          {{ dialog === 'consent' ? 'Registrar' : dialog === 'revoke' ? 'Revogar' : 'Anonimizar definitivamente' }}
        </BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
