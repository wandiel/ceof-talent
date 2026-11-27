<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { parseApiError } from '@/api/http'
import { departmentsApi, unitsApi, usersApi, type UserPayload } from '@/api/resources'
import type { Department, RoleDefinition, Unit, User } from '@/api/types'
import PasswordRules from '@/components/PasswordRules.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ open: boolean; user: User | null; roles: RoleDefinition[] }>()
const emit = defineEmits<{ close: []; saved: [User] }>()

const auth = useAuthStore()
const toast = useToastStore()
const units = ref<Unit[]>([])
const departments = ref<Department[]>([])
const errors = ref<Record<string, string>>({})
const saving = ref(false)

const empty = (): UserPayload & { password: string } => ({
  name: '', email: '', job_title: '', phone: '', unit_id: null, department_id: null, roles: [], password: '',
})
const form = reactive(empty())

const isEdit = computed(() => props.user !== null)
const isAdmin = computed(() => auth.user?.roles.some((r) => r.name === 'admin') ?? false)

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    errors.value = {}
    Object.assign(form, empty())
    if (props.user) {
      Object.assign(form, {
        name: props.user.name,
        email: props.user.email,
        job_title: props.user.job_title ?? '',
        phone: props.user.phone ?? '',
        unit_id: props.user.unit?.id ?? null,
        department_id: props.user.department?.id ?? null,
        roles: props.user.roles.map((r) => r.name),
      })
    }
    try {
      ;[units.value, departments.value] = await Promise.all([unitsApi.options(), departmentsApi.options()])
    } catch (e) {
      toast.error(parseApiError(e).message)
    }
  },
)

async function submit() {
  saving.value = true
  errors.value = {}
  const { password, ...rest } = form
  const payload: UserPayload = { ...rest, job_title: rest.job_title || null, phone: rest.phone || null }
  try {
    const saved = props.user ? await usersApi.update(props.user.id, payload) : await usersApi.create({ ...payload, password })
    toast.success(props.user ? 'Usuário atualizado.' : 'Usuário criado. Informe a senha provisória ao usuário por um canal seguro.')
    emit('saved', saved)
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = Object.fromEntries(Object.entries(parsed.fields).map(([k, v]) => [k.startsWith('roles') ? 'roles' : k, v]))
    if (!Object.keys(parsed.fields).length) toast.error(parsed.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :open="open" :title="isEdit ? 'Editar usuário' : 'Novo usuário'" size="lg" @close="emit('close')">
    <form id="user-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
      <FormField class="sm:col-span-2" label="Nome completo" for="u-name" :error="errors.name" required>
        <input id="u-name" v-model.trim="form.name" :class="inputClass(errors.name)" maxlength="150" />
      </FormField>
      <FormField label="E-mail" for="u-email" :error="errors.email" required>
        <input id="u-email" v-model.trim="form.email" type="email" :class="inputClass(errors.email)" />
      </FormField>
      <FormField label="Telefone" for="u-phone" :error="errors.phone">
        <input id="u-phone" v-model.trim="form.phone" type="tel" :class="inputClass(errors.phone)" placeholder="(92) 99999-0000" />
      </FormField>
      <FormField label="Cargo/função" for="u-job" :error="errors.job_title">
        <input id="u-job" v-model.trim="form.job_title" :class="inputClass(errors.job_title)" />
      </FormField>
      <FormField label="Unidade" for="u-unit" :error="errors.unit_id">
        <select id="u-unit" v-model="form.unit_id" :class="inputClass(errors.unit_id)">
          <option :value="null">Nenhuma</option>
          <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
        </select>
      </FormField>
      <FormField class="sm:col-span-2" label="Departamento" for="u-dep" :error="errors.department_id" hint="Obrigatório para Gestores de departamento: define quais vagas e candidatos eles acessam.">
        <select id="u-dep" v-model="form.department_id" :class="inputClass(errors.department_id)">
          <option :value="null">Nenhum</option>
          <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
      </FormField>

      <fieldset class="sm:col-span-2">
        <legend class="mb-2 text-sm font-medium text-slate-700">Perfis de acesso <span class="text-red-600">*</span></legend>
        <div class="grid gap-2 sm:grid-cols-2">
          <label
            v-for="role in roles"
            :key="role.name"
            class="flex cursor-pointer gap-3 rounded-lg border p-3 text-sm"
            :class="[form.roles.includes(role.name) ? 'border-brand-300 bg-brand-50' : 'border-slate-200', role.name === 'admin' && !isAdmin ? 'cursor-not-allowed opacity-50' : '']"
          >
            <input v-model="form.roles" type="checkbox" :value="role.name" :disabled="role.name === 'admin' && !isAdmin" class="mt-0.5 size-4 rounded border-slate-300 text-brand-700" />
            <span>
              <span class="block font-medium text-slate-800">{{ role.label }}</span>
              <span class="block text-xs text-slate-500">{{ role.description }}</span>
            </span>
          </label>
        </div>
        <p v-if="errors.roles" class="mt-1 text-xs text-red-600" role="alert">{{ errors.roles }}</p>
      </fieldset>

      <template v-if="!isEdit">
        <FormField class="sm:col-span-2" label="Senha provisória" for="u-pass" :error="errors.password" hint="O usuário deverá trocá-la no primeiro acesso." required>
          <input id="u-pass" v-model="form.password" type="text" autocomplete="off" :class="inputClass(errors.password)" />
        </FormField>
        <PasswordRules class="sm:col-span-2" :password="form.password" />
      </template>
    </form>

    <template #footer>
      <BaseButton variant="secondary" @click="emit('close')">Cancelar</BaseButton>
      <BaseButton type="submit" form="user-form" :loading="saving">{{ isEdit ? 'Salvar alterações' : 'Criar usuário' }}</BaseButton>
    </template>
  </BaseModal>
</template>
