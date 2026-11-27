<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { parseApiError } from '@/api/http'
import { portalApi, receiptStore, type PortalOptions, type PublicJob } from '@/api/portal'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'

const route = useRoute()
const router = useRouter()
const options = ref<PortalOptions | null>(null)
const job = ref<PublicJob | null>(null)
const jobError = ref('')
const sending = ref(false)
const generalError = ref('')
const errors = ref<Record<string, string>>({})

interface PortalForm {
  full_name: string
  social_name: string
  email: string
  phone: string
  cpf: string
  birth_date: string
  city: string
  state: string
  professional_area: string
  education_level: string
  desired_position: string
  linkedin_url: string
  summary: string
  privacy_accepted: boolean
  talent_pool_consent: boolean
  website: string
}

const fields = reactive<PortalForm>({
  full_name: '',
  social_name: '',
  email: '',
  phone: '',
  cpf: '',
  birth_date: '',
  city: '',
  state: 'AM',
  professional_area: '',
  education_level: '',
  desired_position: '',
  linkedin_url: '',
  summary: '',
  privacy_accepted: false,
  talent_pool_consent: true,
  website: '',
})
const resume = ref<File | null>(null)
const documents = ref<File[]>([])

onMounted(async () => {
  options.value = await portalApi.options().catch(() => null)
  const id = typeof route.query.vaga === 'string' ? route.query.vaga : null
  if (id) {
    try {
      job.value = await portalApi.job(id)
    } catch {
      jobError.value = 'A vaga escolhida não está mais recebendo inscrições. Você ainda pode enviar o currículo para o banco de talentos.'
    }
  }
})

const maxBytes = computed(() => (options.value?.max_file_mb ?? 10) * 1024 * 1024)
const accept = '.pdf,.doc,.docx,.odt,.jpg,.jpeg,.png'

function pickResume(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] ?? null
  errors.value.resume = f && f.size > maxBytes.value ? `O arquivo passa de ${options.value?.max_file_mb ?? 10} MB.` : ''
  resume.value = f
}
function pickDocuments(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  const max = options.value?.max_extra_documents ?? 3
  errors.value.documents = files.length > max ? `Envie no máximo ${max} documentos.` : files.some((f) => f.size > maxBytes.value) ? 'Algum arquivo passa do tamanho máximo.' : ''
  documents.value = files.slice(0, max)
}

const field = (k: string) => errors.value[k]
const cls = (k: string) => inputClass(field(k))

async function submit() {
  errors.value = {}
  generalError.value = ''
  if (!resume.value) errors.value.resume = 'Anexe o seu currículo.'
  if (!fields.privacy_accepted) errors.value.privacy_accepted = 'Para enviar, confirme que leu o aviso de privacidade.'
  if (Object.values(errors.value).some(Boolean)) {
    generalError.value = 'Confira os campos destacados.'
    return
  }

  const data = new FormData()
  for (const [k, v] of Object.entries(fields)) {
    if (typeof v === 'boolean') data.append(k, v ? '1' : '0')
    else if (v !== '' || k === 'website') data.append(k, v)
  }
  if (fields.website === '') data.delete('website')
  if (job.value) data.append('job', job.value.id)
  data.append('resume', resume.value as File)
  documents.value.forEach((f) => data.append('documents[]', f))

  sending.value = true
  try {
    const receipt = await portalApi.submit(data)
    receiptStore.save(receipt)
    router.push({ name: 'portal-receipt' })
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = Object.fromEntries(Object.entries(parsed.fields).map(([k, v]) => [k.replace(/\.\d+$/, ''), v]))
    generalError.value = parsed.message
    requestAnimationFrame(() => document.getElementById('form-errors')?.focus())
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <RouterLink :to="job ? `/trabalhe-conosco/vagas/${job.id}` : '/trabalhe-conosco'" class="text-sm text-slate-600 hover:text-brand-700">← Voltar</RouterLink>
    <h1 class="mt-3 text-2xl font-semibold text-slate-900">{{ job ? 'Candidatura' : 'Enviar currículo' }}</h1>
    <p v-if="job" class="mt-1 text-slate-600">Vaga: <strong class="text-slate-900">{{ job.title }}</strong> ({{ job.code }})</p>
    <p v-else class="mt-1 text-slate-600">O seu currículo vai para o banco de talentos do Hospital de Olhos CEOF e será considerado nas próximas vagas.</p>
    <p v-if="jobError" class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ jobError }}</p>

    <form class="mt-6 space-y-6" novalidate @submit.prevent="submit">
      <div v-if="generalError" id="form-errors" tabindex="-1" role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ generalError }}</div>

      <fieldset class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <legend class="px-1 font-semibold text-slate-900">Dados pessoais</legend>
        <div class="mt-2 grid gap-4 sm:grid-cols-2">
          <FormField label="Nome completo" for="p-name" required :error="field('full_name')" class="sm:col-span-2">
            <input id="p-name" v-model="fields.full_name" autocomplete="name" :class="cls('full_name')" maxlength="150" />
          </FormField>
          <FormField label="Nome social" for="p-social" hint="Opcional. É assim que vamos chamar você." :error="field('social_name')" class="sm:col-span-2">
            <input id="p-social" v-model="fields.social_name" :class="cls('social_name')" maxlength="150" />
          </FormField>
          <FormField label="E-mail" for="p-email" required :error="field('email')">
            <input id="p-email" v-model="fields.email" type="email" autocomplete="email" :class="cls('email')" />
          </FormField>
          <FormField label="Telefone com DDD" for="p-phone" required :error="field('phone')">
            <input id="p-phone" v-model="fields.phone" type="tel" autocomplete="tel" placeholder="(92) 99999-9999" :class="cls('phone')" />
          </FormField>
          <FormField label="CPF" for="p-cpf" hint="Opcional. Ajuda a evitar cadastros duplicados." :error="field('cpf')">
            <input id="p-cpf" v-model="fields.cpf" inputmode="numeric" placeholder="000.000.000-00" :class="cls('cpf')" />
          </FormField>
          <FormField label="Data de nascimento" for="p-birth" hint="Opcional." :error="field('birth_date')">
            <input id="p-birth" v-model="fields.birth_date" type="date" :class="cls('birth_date')" />
          </FormField>
          <FormField label="Cidade" for="p-city" required :error="field('city')">
            <input id="p-city" v-model="fields.city" autocomplete="address-level2" :class="cls('city')" />
          </FormField>
          <FormField label="UF" for="p-state" required :error="field('state')">
            <select id="p-state" v-model="fields.state" :class="cls('state')">
              <option v-for="s in options?.states ?? ['AM']" :key="s" :value="s">{{ s }}</option>
            </select>
          </FormField>
        </div>
      </fieldset>

      <fieldset class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <legend class="px-1 font-semibold text-slate-900">Perfil profissional</legend>
        <div class="mt-2 grid gap-4 sm:grid-cols-2">
          <FormField label="Área profissional" for="p-area" required :error="field('professional_area')">
            <select id="p-area" v-model="fields.professional_area" :class="cls('professional_area')">
              <option value="" disabled>Selecione</option>
              <option v-for="a in options?.areas ?? []" :key="a.value" :value="a.value">{{ a.label }}</option>
            </select>
          </FormField>
          <FormField label="Escolaridade" for="p-education" required :error="field('education_level')">
            <select id="p-education" v-model="fields.education_level" :class="cls('education_level')">
              <option value="" disabled>Selecione</option>
              <option v-for="e in options?.education_levels ?? []" :key="e.value" :value="e.value">{{ e.label }}</option>
            </select>
          </FormField>
          <FormField label="Cargo pretendido" for="p-position" :error="field('desired_position')">
            <input id="p-position" v-model="fields.desired_position" :class="cls('desired_position')" maxlength="150" />
          </FormField>
          <FormField label="LinkedIn" for="p-linkedin" hint="Opcional. Cole o endereço do seu perfil." :error="field('linkedin_url')">
            <input id="p-linkedin" v-model="fields.linkedin_url" type="url" :class="cls('linkedin_url')" />
          </FormField>
          <FormField label="Fale um pouco sobre você" for="p-summary" hint="Opcional. Experiências, cursos, disponibilidade." :error="field('summary')" class="sm:col-span-2">
            <textarea id="p-summary" v-model="fields.summary" rows="4" maxlength="3000" :class="cls('summary')" />
          </FormField>
        </div>
      </fieldset>

      <fieldset class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <legend class="px-1 font-semibold text-slate-900">Arquivos</legend>
        <div class="mt-2 grid gap-4">
          <FormField label="Currículo" for="p-resume" required :hint="`PDF, Word (DOC ou DOCX), ODT, JPG ou PNG, até ${options?.max_file_mb ?? 10} MB.`" :error="field('resume')">
            <input id="p-resume" type="file" :accept="accept" class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-medium file:text-brand-800 hover:file:bg-brand-100" @change="pickResume" />
          </FormField>
          <FormField label="Outros documentos" for="p-docs" :hint="`Opcional. Certificados, registro profissional etc. Até ${options?.max_extra_documents ?? 3} arquivos.`" :error="field('documents')">
            <input id="p-docs" type="file" multiple :accept="accept" class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:font-medium file:text-slate-700 hover:file:bg-slate-200" @change="pickDocuments" />
          </FormField>
          <p class="text-xs text-slate-500">Não inclua no currículo informações como religião, opinião política, dados de saúde ou foto de documentos. Elas não são necessárias para o processo seletivo.</p>
        </div>
      </fieldset>

      <fieldset class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <legend class="px-1 font-semibold text-slate-900">Privacidade</legend>
        <div class="mt-2 space-y-3 text-sm text-slate-700">
          <label class="flex gap-3">
            <input id="p-privacy" v-model="fields.privacy_accepted" type="checkbox" class="mt-0.5 size-4 rounded border-slate-300 text-brand-700" :aria-invalid="!!field('privacy_accepted')" />
            <span>
              Li o <RouterLink to="/privacidade" target="_blank" class="font-medium text-brand-700 underline">aviso de privacidade</RouterLink> e entendo que os meus dados serão usados pelo
              {{ options?.privacy.controller ?? 'Hospital de Olhos CEOF' }} para este processo de recrutamento. <span class="text-red-600">*</span>
            </span>
          </label>
          <p v-if="field('privacy_accepted')" class="text-xs text-red-600" role="alert">{{ field('privacy_accepted') }}</p>
          <label class="flex gap-3">
            <input id="p-pool" v-model="fields.talent_pool_consent" type="checkbox" class="mt-0.5 size-4 rounded border-slate-300 text-brand-700" />
            <span>Autorizo manter o meu currículo no banco de talentos por até {{ options?.privacy.talent_pool_retention_months ?? 24 }} meses para outras vagas. Posso retirar essa autorização quando quiser.</span>
          </label>
        </div>
      </fieldset>

      <!-- Campo armadilha para robôs: escondido de pessoas e de leitores de tela. -->
      <div class="absolute -left-[9999px]" aria-hidden="true">
        <label for="p-website">Site</label>
        <input id="p-website" v-model="fields.website" tabindex="-1" autocomplete="off" />
      </div>

      <div class="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-end">
        <p class="text-xs text-slate-500 sm:mr-auto">Campos com <span class="text-red-600">*</span> são obrigatórios.</p>
        <BaseButton type="submit" :loading="sending">{{ job ? 'Enviar candidatura' : 'Enviar currículo' }}</BaseButton>
      </div>
    </form>
  </div>
</template>
