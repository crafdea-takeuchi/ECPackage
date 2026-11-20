<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ lines: Object, filters: Object, counts: Object });
const search = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
const allocating = ref(false);
const requestingId = ref(null);
const number = (value, scale = 4) => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: scale }).format(Number(value) || 0);
const dateTime = value => value ? new Intl.DateTimeFormat('ja-JP', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '';
function submit() { router.get('/admin/backorders', search, { preserveState: true, replace: true }); }
function reset() { Object.assign(search, { q: '', status: '' }); router.get('/admin/backorders'); }
function allocate() { allocating.value = true; router.post('/admin/backorders/allocate', {}, { preserveScroll: true, onFinish: () => { allocating.value = false; } }); }
function orderOf(item) { return item.order_line.order; }
function statusOf(item) {
  if (orderOf(item).status === 'cancelled') return 'cancelled';
  if (Number(item.remaining_quantity) <= 0) return 'fulfilled';
  return item.requested_at ? 'pending' : 'unrequested';
}
function label(item) {
  return { unrequested: '未依頼', pending: '取り寄せ中', fulfilled: '入荷済み', cancelled: 'キャンセル' }[statusOf(item)];
}
function statusClass(item) {
  return {
    unrequested: 'bg-slate-100 text-slate-800',
    pending: 'bg-amber-50 text-amber-800',
    fulfilled: 'bg-emerald-50 text-emerald-800',
    cancelled: 'bg-red-50 text-red-800',
  }[statusOf(item)];
}
function markRequested(item) {
  if (requestingId.value !== null || !window.confirm('この取り寄せを依頼済みにしますか？')) return;
  requestingId.value = item.id;
  router.post('/admin/backorders/' + item.id + '/mark-requested', {}, {
    preserveScroll: true,
    onFinish: () => { requestingId.value = null; },
  });
}
</script>

<template>
  <AdminLayout>
    <Head title="取り寄せ管理" />
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="text-xs font-bold tracking-widest text-emerald-700">取り寄せ</p>
        <h1 class="mt-3 text-3xl font-bold">取り寄せ管理</h1>
        <p class="mt-3 text-sm text-slate-500">在庫不足で受け付けた商品と残数を確認します。仕入先へ依頼したら「依頼済みにする」を実行してください。</p>
      </div>
      <button type="button" class="btn-primary" :disabled="allocating" @click="allocate">{{ allocating ? '再引当中…' : '現在庫から再引当' }}</button>
    </div>

    <div class="my-7 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="item in [
        { label: 'すべて', value: counts.all },
        { label: '未依頼', value: counts.unrequested },
        { label: '取り寄せ中', value: counts.pending },
        { label: '入荷済み', value: counts.fulfilled },
      ]" :key="item.label" class="rounded-xl border border-slate-200 bg-white p-5">
        <p class="text-xs text-slate-500">{{ item.label }}</p>
        <p class="mt-2 text-2xl font-semibold">{{ item.value }}<span class="ml-2 text-xs font-normal text-slate-400">件</span></p>
      </div>
    </div>

    <form class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-[1fr_14rem_auto]" @submit.prevent="submit">
      <div>
        <label for="backorder-q" class="mb-2 block text-sm font-medium">注文番号・商品・お客様</label>
        <input id="backorder-q" v-model="search.q" class="field" maxlength="150" />
      </div>
      <div>
        <label for="backorder-status" class="mb-2 block text-sm font-medium">状態</label>
        <select id="backorder-status" v-model="search.status" class="field">
          <option value="">すべて</option>
          <option value="unrequested">未依頼</option>
          <option value="pending">取り寄せ中</option>
          <option value="fulfilled">入荷済み</option>
          <option value="cancelled">キャンセル</option>
        </select>
      </div>
      <div class="flex items-end gap-3">
        <button type="submit" class="btn-primary">検索</button>
        <button type="button" class="nav-link px-2 py-3" @click="reset">解除</button>
      </div>
    </form>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-slate-500">
            <tr>
              <th class="px-5 py-4">注文</th>
              <th class="px-5 py-4">商品</th>
              <th class="px-5 py-4">お客様</th>
              <th class="px-5 py-4 text-right">当初不足</th>
              <th class="px-5 py-4 text-right">残数</th>
              <th class="px-5 py-4">状態</th>
              <th class="px-5 py-4"><span class="sr-only">操作</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in lines.data" :key="line.id" class="border-t border-slate-100">
              <td class="px-5 py-4">
                <Link :href="'/admin/orders/' + orderOf(line).id + '/edit'" class="font-semibold text-emerald-900">{{ orderOf(line).order_number }}</Link>
                <p class="mt-1 text-xs text-slate-500">{{ orderOf(line).ordered_on }}</p>
              </td>
              <td class="px-5 py-4">
                <p v-if="line.order_line.product.kind === 'bundle'" class="mb-1 text-xs text-blue-700">{{ line.order_line.product_name }} の構成商品</p>
                <p class="font-semibold">{{ line.stock_product_name }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ line.stock_product_sku }}</p>
              </td>
              <td class="px-5 py-4">
                <p>{{ orderOf(line).customer.name }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ orderOf(line).customer.email }}</p>
              </td>
              <td class="px-5 py-4 text-right tabular-nums">{{ number(line.initial_quantity, line.stock_product.unit.quantity_scale) }} {{ line.stock_product.unit.name }}</td>
              <td class="px-5 py-4 text-right font-semibold tabular-nums">{{ number(line.remaining_quantity, line.stock_product.unit.quantity_scale) }} {{ line.stock_product.unit.name }}</td>
              <td class="px-5 py-4">
                <span class="rounded-full px-3 py-1 text-xs" :class="statusClass(line)">{{ label(line) }}</span>
                <p v-if="line.requested_at" class="mt-2 text-xs text-slate-500">
                  {{ dateTime(line.requested_at) }}<span v-if="line.requester">・{{ line.requester.name }}</span>
                </p>
              </td>
              <td class="px-5 py-4 text-right">
                <button
                  v-if="statusOf(line) === 'unrequested'"
                  type="button"
                  class="whitespace-nowrap rounded-lg border border-emerald-700 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 disabled:opacity-50"
                  :disabled="requestingId !== null"
                  @click="markRequested(line)"
                >
                  {{ requestingId === line.id ? '更新中…' : '依頼済みにする' }}
                </button>
              </td>
            </tr>
            <tr v-if="!lines.data.length"><td colspan="7" class="px-6 py-14 text-center">取り寄せ商品はありません。</td></tr>
          </tbody>
        </table>
      </div>
      <nav class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm">
        <span>{{ lines.current_page }} / {{ lines.last_page }} ページ</span>
        <div class="flex gap-5">
          <Link v-if="lines.prev_page_url" :href="lines.prev_page_url" class="nav-link">← 前へ</Link>
          <Link v-if="lines.next_page_url" :href="lines.next_page_url" class="nav-link">次へ →</Link>
        </div>
      </nav>
    </section>
  </AdminLayout>
</template>