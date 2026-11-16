<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ cards: { type: Array, required: true } });
</script>

<template>
  <div class="grid grid-cols-1 gap-5" :class="cards.length === 1 ? 'w-full max-w-2xl' : 'md:grid-cols-2 xl:grid-cols-3'">
    <section v-for="card in cards" :key="card.title" class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white p-6">
      <h2 class="text-lg font-semibold">{{ card.title }}</h2>
      <p class="mt-3 text-sm leading-6 text-slate-600">{{ card.description }}</p>
      <p :role="card.warning ? 'alert' : undefined" class="mt-3 whitespace-pre-line break-words text-sm font-medium" :class="card.warning ? 'rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900' : 'text-slate-700'">{{ card.status }}</p>
      <p v-if="card.account_number" class="mt-2 text-sm text-slate-600">口座番号：{{ card.account_number }}</p>
      <div class="mt-auto pt-5"><Link v-if="card.href" :href="card.href" class="nav-link inline-block rounded-lg border border-emerald-200 px-4 py-2 text-sm font-semibold">{{ card.button }}</Link></div>
    </section>
  </div>
</template>
