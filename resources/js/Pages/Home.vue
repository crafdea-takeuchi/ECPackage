<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
const page = usePage();
</script>
<template>
  <Head title="ホーム" />
  <AppLayout>
    <section class="relative overflow-hidden rounded-3xl bg-emerald-950 px-8 py-16 text-white md:px-16 md:py-24">
      <div aria-hidden="true" class="absolute -right-20 -top-20 h-80 w-80 rounded-full border-[50px] border-white/5"></div>
      <div class="relative max-w-xl">
        <p class="mb-5 text-xs font-semibold tracking-[.25em] text-emerald-300">WELCOME TO EC02</p>
        <h1 class="text-3xl font-semibold leading-relaxed md:text-5xl md:leading-snug">毎日に、<br>新しい楽しみを。</h1>
        <p class="mt-6 max-w-md text-sm leading-8 text-emerald-100">{{ page.props.auth.user ? `${page.props.auth.user.name} さん、おかえりなさい。` : 'EC02へようこそ。アカウントを作成して、これから始まるお買い物に備えましょう。' }}</p>
        <div class="mt-9 flex flex-wrap items-start gap-3"><Link href="/shop" class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-emerald-950 hover:bg-emerald-50">商品を見る →</Link>
          <template v-if="!page.props.auth.user"><div class="flex flex-col items-start gap-1"><span role="button" aria-disabled="true" class="cursor-not-allowed rounded-lg bg-white/40 px-6 py-3 text-sm font-semibold text-emerald-950/60">新規登録する →</span><p class="text-xs font-semibold text-red-400">現在停止中です</p></div><Link href="/login" class="rounded-lg border border-white/40 px-6 py-3 text-sm hover:bg-white/10">ログイン</Link></template>
          <Link v-else-if="page.props.auth.user.role === 'admin'" href="/admin" class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-emerald-950">管理画面へ →</Link>
          <span v-else class="rounded-full bg-white/10 px-4 py-2 text-sm">ログイン中</span>
        </div>
      </div>
    </section>
    <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-8">
      <p class="text-xs font-semibold tracking-widest text-emerald-700">INFORMATION</p>
      <h2 class="mt-3 text-lg font-semibold">オンラインショップを公開しました</h2>
      <p class="mt-3 text-sm leading-7 text-slate-500">商品一覧から在庫を確認し、そのままカートへ追加できます。</p>
    </section>
  </AppLayout>
</template>
