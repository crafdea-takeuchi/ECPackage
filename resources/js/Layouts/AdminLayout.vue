<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from './AppLayout.vue';

const page = usePage();
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
  return link.exact ? page.url === link.href : page.url.startsWith(link.prefix);
}
</script>

<template>
  <AppLayout>
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
      <div class="flex justify-end pt-1">
        <Link href="/" class="nav-link px-4 py-2 text-sm">お客様側を見る ↗</Link>
      </div>
    </nav>
    <slot />
  </AppLayout>
</template>
