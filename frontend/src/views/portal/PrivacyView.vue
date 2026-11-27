<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { formatDateBr, portalApi, type PrivacyInfo } from '@/api/portal'

const info = ref<PrivacyInfo | null>(null)
onMounted(async () => {
  info.value = (await portalApi.options().catch(() => null))?.privacy ?? null
})
</script>

<template>
  <article class="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
    <h1 class="text-2xl font-semibold text-slate-900">Aviso de privacidade · Trabalhe conosco</h1>
    <p class="mt-1 text-sm text-slate-500">Versão {{ info?.version ?? '—' }} · atualizado em {{ formatDateBr(info?.updated_at ?? null) }}</p>

    <div class="mt-6 space-y-6 leading-relaxed text-slate-700">
      <section>
        <h2 class="text-lg font-semibold text-slate-900">1. Quem cuida dos seus dados</h2>
        <p>O <strong>{{ info?.controller ?? 'Hospital de Olhos CEOF' }}</strong> é o controlador dos dados pessoais enviados por este portal, conforme a Lei Geral de Proteção de Dados (Lei 13.709/2018, LGPD).</p>
      </section>
      <section>
        <h2 class="text-lg font-semibold text-slate-900">2. Quais dados coletamos</h2>
        <p>Os dados que você informa no formulário (nome, nome social, e-mail, telefone, CPF e data de nascimento quando informados, cidade, área, escolaridade, cargo pretendido, resumo e LinkedIn), o currículo e os documentos que você anexar, e registros técnicos do envio (data, hora, endereço IP e navegador), usados para segurança e para comprovar o seu aceite.</p>
        <p class="mt-2">Não pedimos dados sensíveis (como origem racial, religião, opinião política, saúde ou vida sexual). Pedimos que você não os inclua no currículo.</p>
      </section>
      <section>
        <h2 class="text-lg font-semibold text-slate-900">3. Para que usamos</h2>
        <ul class="list-disc space-y-1 pl-6">
          <li>Avaliar a sua candidatura e conduzir o processo seletivo (triagem, entrevistas e contato com você).</li>
          <li>Se você autorizar, manter o seu currículo no banco de talentos para outras vagas.</li>
          <li>Cumprir obrigações legais e nos defender em eventuais processos.</li>
        </ul>
        <p class="mt-2">A leitura automática do currículo serve só para organizar as informações e sugerir competências. Nenhuma decisão sobre você é tomada de forma automática: a análise é sempre feita por pessoas da equipe.</p>
      </section>
      <section>
        <h2 class="text-lg font-semibold text-slate-900">4. Bases legais</h2>
        <p>Procedimentos preliminares a um possível contrato de trabalho, a seu pedido (art. 7º, V, da LGPD), para o processo seletivo; o seu consentimento (art. 7º, I) para manter o currículo no banco de talentos; e o cumprimento de obrigação legal e o exercício regular de direitos (art. 7º, II e VI), quando aplicável.</p>
      </section>
      <section>
        <h2 class="text-lg font-semibold text-slate-900">5. Com quem compartilhamos</h2>
        <p>Os dados são acessados apenas pela equipe de Recursos Humanos e pelas pessoas que participam do processo seletivo (gestores e entrevistadores), cada uma só com o que precisa. Podem ser tratados por fornecedores de tecnologia que hospedam o sistema, sob contrato e dever de sigilo, e informados a autoridades quando a lei exigir. Não vendemos nem cedemos os seus dados.</p>
      </section>
      <section>
        <h2 class="text-lg font-semibold text-slate-900">6. Por quanto tempo guardamos</h2>
        <p>Durante o processo seletivo e, se você autorizou o banco de talentos, por até {{ info?.talent_pool_retention_months ?? 24 }} meses depois da sua última atualização ou candidatura. Sem essa autorização, os dados são mantidos só pelo tempo do processo e pelo período necessário para obrigações legais. Depois disso, são excluídos ou anonimizados.</p>
      </section>
      <section>
        <h2 class="text-lg font-semibold text-slate-900">7. Segurança</h2>
        <p>Usamos conexão protegida, controle de acesso por perfil, registro de quem acessa os dados, armazenamento privado dos arquivos e criptografia de dados como o CPF.</p>
      </section>
      <section>
        <h2 class="text-lg font-semibold text-slate-900">8. Seus direitos</h2>
        <p>Você pode pedir, a qualquer momento: confirmação de que tratamos os seus dados e acesso a eles; correção de dados incompletos ou desatualizados; anonimização, bloqueio ou exclusão de dados desnecessários; portabilidade; informação sobre compartilhamento; e a retirada do consentimento do banco de talentos (art. 18 da LGPD).</p>
      </section>
      <section>
        <h2 class="text-lg font-semibold text-slate-900">9. Como falar com a gente</h2>
        <p v-if="info?.dpo_email">
          Encarregado de dados<span v-if="info.dpo_name"> ({{ info.dpo_name }})</span>: <a :href="`mailto:${info.dpo_email}`" class="font-medium text-brand-700 underline">{{ info.dpo_email }}</a>. Informe o número do protocolo do seu envio, se tiver.
        </p>
        <p v-else>Procure o setor de Recursos Humanos do {{ info?.controller ?? 'Hospital de Olhos CEOF' }} e informe o número do protocolo do seu envio, se tiver.</p>
      </section>
    </div>
  </article>
</template>
