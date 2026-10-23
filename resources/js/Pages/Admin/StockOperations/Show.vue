<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

defineProps({ operation: Object });
const number = (value, scale = 4) => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: scale }).format(Number(value) || 0);
</script>

<template>
  <AdminLayout>
    <Head title="在庫履歴の詳細" />
    <Link href="/admin/stock-operations" class="nav-link text-sm">← 履歴一覧へ</Link>
    <div class="mt-5 mb-7 flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold tracking-widest text-emerald-700">在庫履歴の詳細</p><h1 class="mt-3 text-3xl font-bold">{{ operation.operation_type === 'outbound' ? '出庫' : (operation.operation_type === 'cancellation_return' ? 'キャンセル戻し' : (operation.operation_type === 'backorder_allocation' ? '取り寄せ引当' : '在庫調整')) }}</h1><p class="mt-3 text-sm text-slate-500">確定した履歴です。訂正が必要な場合は新しい在庫調整を登録してください。</p></div><div><p class="text-xs text-slate-500">処理番号</p><p class="mt-1 font-semibold">{{ operation.operation_number }}</p></div></div>
    <section class="mb-6 grid gap-5 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-2 lg:grid-cols-4"><div><p class="text-xs text-slate-500">処理日</p><p class="mt-2 font-semibold">{{ operation.occurred_on }}</p></div><div><p class="text-xs text-slate-500">処理区分</p><p class="mt-2 font-semibold">{{ operation.operation_type === 'outbound' ? '出庫' : (operation.operation_type === 'cancellation_return' ? 'キャンセル戻し' : (operation.operation_type === 'backorder_allocation' ? '取り寄せ引当' : '在庫調整')) }}</p></div><div><p class="text-xs text-slate-500">登録者</p><p class="mt-2 font-semibold">{{ operation.creator.name }}</p></div><div><p class="text-xs text-slate-500">備考</p><p class="mt-2 whitespace-pre-wrap">{{ operation.notes || '—' }}</p></div></section>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><div class="border-b border-slate-200 px-5 py-4 font-semibold">明細</div><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">販売商品・ロット</th><th class="px-5 py-4">仕入先</th><th class="px-5 py-4 text-right">処理前</th><th class="px-5 py-4 text-right">増減</th><th class="px-5 py-4 text-right">処理後</th><th class="px-5 py-4">理由・用途</th></tr></thead><tbody><tr v-for="line in operation.lines" :key="line.id" class="border-t border-slate-100"><td class="px-5 py-4"><p class="font-semibold text-emerald-900">{{ line.product.name }}</p><p class="mt-1 text-xs text-slate-500">{{ line.product.sku }} ／ {{ line.inventory_lot.lot_number }}</p></td><td class="px-5 py-4">{{ line.inventory_lot.supplier.name }}</td><td class="px-5 py-4 text-right tabular-nums">{{ number(line.quantity_before, line.product.unit.quantity_scale) }} {{ line.product.unit.name }}</td><td class="px-5 py-4 text-right font-semibold tabular-nums" :class="Number(line.quantity_delta) < 0 ? 'text-red-700' : 'text-emerald-700'">{{ Number(line.quantity_delta) > 0 ? '+' : '' }}{{ number(line.quantity_delta, line.product.unit.quantity_scale) }}</td><td class="px-5 py-4 text-right font-semibold tabular-nums">{{ number(line.quantity_after, line.product.unit.quantity_scale) }} {{ line.product.unit.name }}</td><td class="px-5 py-4">{{ line.reason || '—' }}</td></tr></tbody></table></div></section>
  </AdminLayout>
</template>
