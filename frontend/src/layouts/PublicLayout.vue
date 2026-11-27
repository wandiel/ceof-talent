<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
import logoUrl from '@/assets/logo-ceof.png'
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <a href="#conteudo" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded focus:bg-white focus:px-3 focus:py-2">Pular para o conteúdo</a>
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
        <RouterLink to="/trabalhe-conosco" class="flex items-center gap-3">
          <img :src="logoUrl" alt="Hospital de Olhos CEOF" class="h-10 w-auto" />
          <span class="border-l border-slate-200 pl-3 text-sm font-semibold tracking-wide text-slate-800">Trabalhe conosco</span>
        </RouterLink>
        <nav aria-label="Portal" class="flex items-center gap-1 text-sm">
          <RouterLink to="/trabalhe-conosco" class="rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100" exact-active-class="bg-brand-50 text-brand-800 font-medium">Vagas abertas</RouterLink>
          <RouterLink to="/trabalhe-conosco/cadastro" class="rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100" exact-active-class="bg-brand-50 text-brand-800 font-medium">Enviar currículo</RouterLink>
        </nav>
      </div>
    </header>

    <main id="conteudo" class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6">
      <RouterView />
    </main>

    <footer class="border-t border-slate-200 bg-white">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-sm text-slate-500 sm:px-6">
        <p>© {{ new Date().getFullYear() }} Hospital de Olhos CEOF</p>
        <div class="flex gap-4">
          <RouterLink to="/privacidade" class="hover:text-brand-700 hover:underline">Aviso de privacidade</RouterLink>
          <RouterLink to="/entrar" class="hover:text-brand-700 hover:underline">Acesso da equipe</RouterLink>
        </div>
      </div>
    </footer>
  </div>
</template>
