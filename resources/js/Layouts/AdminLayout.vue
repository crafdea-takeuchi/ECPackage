<script setup>
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminSidebar from '../Components/AdminSidebar.vue';
import AppLayout from './AppLayout.vue';

const page = usePage();
const sidebarOpen = ref(false);
const groups = [
  {
    label: '商品登録・管理',
    description: '販売する商品の情報を整える',
    links: [
      { href: '/admin/products', label: '販売商品', prefix: '/admin/products' },
      { href: '/admin/product-groups', label: '基本商品・属性', prefix: '/admin/product-groups' },
      { href: '/admin/categories', label: '商品分類', prefix: '/admin/categories' },
    ],
  },
  {
    label: '仕入・在庫管理',
    description: '入荷からロット在庫、出庫までを管理する',
    links: [
      { href: '/admin/suppliers', label: '仕入先', prefix: '/admin/suppliers' },
      { href: '/admin/purchase-receipts', label: '入庫', prefix: '/admin/purchase-receipts' },
      { href: '/admin/inventory-lots', label: 'ロット在庫', prefix: '/admin/inventory-lots' },
      { href: '/admin/stock-operations', label: '出庫・在庫調整', prefix: '/admin/stock-operations' },
    ],
  },

  {
    label: '注文・顧客管理',
    description: '受注の進捗と利用者を管理する',
    links: [
      { href: '/admin/orders', label: '注文', prefix: '/admin/orders' },
      { href: '/admin/shipments', label: '発送', prefix: '/admin/shipments' },
      { href: '/admin/backorders', label: '取り寄せ', prefix: '/admin/backorders' },
      { href: '/admin/order-settings', label: '注文設定', prefix: '/admin/order-settings' },
      { href: '/admin', label: 'アカウント', exact: true },
    ],
  },
];

function isActive(link) {
  const path = page.url.split('?')[0];

  return link.exact ? path === link.href : path.startsWith(link.prefix);
}
</script>

<template>
  <AppLayout admin>
    <div class="flex min-h-[calc(100vh-5rem)] items-stretch" @keydown.esc.window="sidebarOpen = false">
      <button
        v-if="sidebarOpen"
        type="button"
        aria-label="管理メニューを閉じる"
        class="fixed inset-0 z-40 bg-slate-950/40 lg:hidden"
        @click="sidebarOpen = false"
      />
      <AdminSidebar :open="sidebarOpen" :groups="groups" @close="sidebarOpen = false" />
      <main class="min-w-0 flex-1 px-6 py-10 md:px-10 md:py-14">
        <div class="mx-auto max-w-6xl">
          <button
            type="button"
            class="mb-8 inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 lg:hidden"
            aria-controls="admin-navigation"
            :aria-expanded="sidebarOpen"
            @click="sidebarOpen = true"
          >
            <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
            管理メニュー
          </button>
          <div v-if="page.props.flash.status" role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ page.props.flash.status }}</div>
          <div v-if="page.props.auth.user && !page.props.auth.user.email_verified_at" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">メールアドレスの確認がまだ完了していません。<Link href="/email/verify" class="ml-2 underline">確認メールの案内を見る</Link></div>

          <nav aria-label="管理メニュー" class="mb-8 space-y-3 border-b border-slate-200 pb-6">
            <section v-for="group in groups" :key="group.label" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:grid-cols-[13rem_1fr] lg:items-center">
              <div>
                <p class="text-sm font-bold text-emerald-950">{{ group.label }}</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ group.description }}</p>
              </div>
              <div class="flex flex-wrap gap-2">
                <Link
                  v-for="link in group.links"
                  :key="link.href"
                  :href="link.href"
                  class="rounded-lg border px-4 py-2 text-sm font-semibold transition"
                  :class="isActive(link) ? 'border-emerald-800 bg-emerald-800 text-white' : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-emerald-300 hover:bg-emerald-50'"
                >
                  {{ link.label }}
                </Link>
              </div>
            </section>
            <!-- 「お客様側を見る」は一時的に非表示 -->
          </nav>

          <slot />
        </div>
      </main>
    </div>
  </AppLayout>
</template>
