<script setup>
import { Link, usePage } from '@inertiajs/vue3';
const page = usePage();
defineProps({ admin: Boolean });
</script>
<template>
  <div class="min-h-screen bg-[#f6f7f4] text-slate-800">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5">
        <Link href="/" class="text-2xl font-bold tracking-tight text-emerald-900">EC<span class="font-normal text-slate-400">02</span></Link>
        <nav aria-label="メインメニュー" class="flex flex-wrap items-center gap-5 text-sm">
          <Link href="/" class="nav-link">ホーム</Link>
          <Link href="/shop" class="nav-link">商品一覧</Link>
          <Link href="/cart" class="nav-link">カート（{{ page.props.cart.count }}）</Link>
          <Link v-if="page.props.auth.user?.role === 'admin'" href="/admin" class="nav-link">管理画面</Link>
          <template v-if="page.props.auth.user">
            <Link v-if="page.props.auth.user.role === 'customer'" href="/orders" class="nav-link">注文履歴</Link>
            <span class="text-slate-500">{{ page.props.auth.user.name }} さん</span>
            <Link href="/logout" method="post" as="button" class="nav-link">ログアウト</Link>
          </template>
          <template v-else>
            <Link href="/login" class="nav-link">ログイン</Link>
            <Link href="/register" class="btn-primary">新規登録</Link>
          </template>
        </nav>
      </div>
    </header>
    <main class="mx-auto max-w-6xl px-6 py-10 md:py-14">
      <div v-if="page.props.flash.status" role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ page.props.flash.status }}</div>
      <div v-if="page.props.auth.user && !page.props.auth.user.email_verified_at" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">メールアドレスの確認がまだ完了していません。<Link href="/email/verify" class="ml-2 underline">確認メールの案内を見る</Link></div>
      <slot />
    </main>
    <footer class="mx-auto flex max-w-6xl flex-wrap justify-between gap-4 px-6 py-8 text-xs text-slate-500">
      <span>EC02 · あなたの毎日に、ちょうどいいものを。</span>
      <Link v-if="!page.props.auth.user" href="/admin/login" class="nav-link">管理者ログイン</Link>
    </footer>
  </div>
</template>
