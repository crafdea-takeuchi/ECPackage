<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import CustomerSidebar from '../Components/CustomerSidebar.vue';

const page = usePage();
const props = defineProps({ admin: Boolean });
const sidebarOpen = ref(false);
const isAdminPage = computed(() => page.url.split('?')[0].startsWith('/admin'));
</script>
<template>
  <div class="min-h-screen bg-[#f6f7f4] text-slate-800" @keydown.esc.window="sidebarOpen = false">
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
    <div v-if="!isAdminPage" class="flex min-h-[calc(100vh-5rem)] items-stretch">
      <button
        v-if="sidebarOpen"
        type="button"
        aria-label="メニューを閉じる"
        class="fixed inset-0 z-40 bg-slate-950/40 lg:hidden"
        @click="sidebarOpen = false"
      />
      <CustomerSidebar :open="sidebarOpen" @close="sidebarOpen = false" />
      <main class="min-w-0 flex-1 px-6 py-10 md:px-10 md:py-14">
        <div class="mx-auto max-w-6xl">
          <button
            type="button"
            class="mb-8 inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 lg:hidden"
            aria-controls="customer-navigation"
            :aria-expanded="sidebarOpen"
            @click="sidebarOpen = true"
          >
            <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
            メニュー
          </button>
          <div v-if="page.props.flash.status" role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ page.props.flash.status }}</div>
          <div v-if="page.props.auth.user && !page.props.auth.user.email_verified_at" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">メールアドレスの確認がまだ完了していません。<Link href="/email/verify" class="ml-2 underline">確認メールの案内を見る</Link></div>
          <slot />
        </div>
      </main>
    </div>
    <slot v-else-if="props.admin" />
    <main v-else class="mx-auto max-w-6xl px-6 py-10 md:py-14">
      <div v-if="page.props.flash.status" role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ page.props.flash.status }}</div>
      <div v-if="page.props.auth.user && !page.props.auth.user.email_verified_at" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">メールアドレスの確認がまだ完了していません。<Link href="/email/verify" class="ml-2 underline">確認メールの案内を見る</Link></div>
      <slot />
    </main>
    <footer class="mx-auto flex max-w-6xl flex-wrap justify-between gap-4 px-6 py-8 text-xs text-slate-500">
      <span>EC02 · あなたの毎日に、ちょうどいいものを。</span>
    </footer>
  </div>
</template>
