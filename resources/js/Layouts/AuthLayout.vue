<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from './AppLayout.vue';
defineProps({ title: String, description: String, admin: Boolean });
</script>
<template>
  <Head :title="title" />
  <AppLayout>
    <div class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm md:p-10">
      <p class="mb-3 text-xs font-bold tracking-widest text-emerald-700">{{ admin ? 'ADMINISTRATION' : 'YOUR ACCOUNT' }}</p>
      <h1 class="text-2xl font-bold tracking-tight">{{ title }}</h1>
      <p class="mb-7 mt-3 text-sm leading-6 text-slate-500">{{ description }}</p>
      <slot />
    </div>
    <div class="mt-6 text-center text-sm"><Link href="/" class="nav-link">← ホームへ戻る</Link></div>
  </AppLayout>
</template>
