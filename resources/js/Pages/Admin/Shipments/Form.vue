<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ shipment: Object, orders: Array, selectedOrderId: Number });
const initialLines = props.shipment?.lines?.map(line => ({ order_line_id: line.order_line_id, quantity: line.quantity })) ?? [];
const form = useForm({
  order_id: props.shipment?.order_id ?? props.selectedOrderId ?? '',
  carrier: props.shipment?.carrier ?? '',
  tracking_number: props.shipment?.tracking_number ?? '',
  scheduled_on: props.shipment?.scheduled_on ?? '',
  shipped_on: props.shipment?.shipped_on ?? '',
  notes: props.shipment?.notes ?? '',
  lock_version: props.shipment?.lock_version ?? 0,
  action: 'draft',
  lines: initialLines,
});
const editable = computed(() => !props.shipment || props.shipment.status === 'draft');
const selectedOrder = computed(() => props.orders.find(order => Number(order.id) === Number(form.order_id)));
const statusLabels = { draft: '発送準備中', shipped: '発送済み', cancelled: '取消' };
const number = (value, scale = 4) => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: scale }).format(Number(value) || 0);
const orderLine = row => selectedOrder.value?.lines.find(line => Number(line.id) === Number(row.order_line_id));
function changeOrder() {
  form.lines = selectedOrder.value?.lines.map(line => ({ order_line_id: line.id, quantity: line.remaining_quantity })) ?? [];
}
if (!props.shipment && form.order_id) changeOrder();
function save(action) {
  form.action = action;
  const options = { preserveScroll: true, onSuccess: () => { if (props.shipment) { form.lock_version = props.shipment.lock_version; form.defaults(); } } };
  if (props.shipment) form.put(`/admin/shipments/${props.shipment.id}`, options); else form.post('/admin/shipments', options);
}
const cancelForm = useForm({ reason: '' });
function cancelShipment() { cancelForm.post(`/admin/shipments/${props.shipment.id}/cancel`, { preserveScroll: true, onSuccess: () => cancelForm.reset() }); }
</script>

<template>
  <AdminLayout>
    <Head :title="shipment ? '発送の詳細' : '発送を登録'" />
    <Link href="/admin/shipments" class="nav-link text-sm">← 発送一覧へ</Link>
    <div class="mt-5 mb-7 flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold tracking-widest text-emerald-700">発送の詳細</p><h1 class="mt-3 text-3xl font-bold">{{ shipment ? (editable ? '発送を編集' : '発送の詳細') : '発送を登録' }}</h1><p class="mt-3 text-sm text-slate-500">発送する注文商品、配送会社、追跡番号を記録します。</p></div><div v-if="shipment"><p class="text-xs text-slate-500">発送番号</p><p class="mt-1 font-semibold">{{ shipment.shipment_number }}</p></div></div>
    <div v-if="shipment" class="mb-6 rounded-xl border border-emerald-100 bg-emerald-50 p-5 text-sm text-emerald-900"><strong>現在の状態：{{ statusLabels[shipment.status] }}</strong><span v-if="shipment.shipped_at" class="ml-3">確定日時：{{ shipment.shipped_at }}</span><span v-if="shipment.cancellation_reason" class="ml-3">理由：{{ shipment.cancellation_reason }}</span></div>
    <form @submit.prevent="save('draft')" class="space-y-6" novalidate>
      <div v-if="form.hasErrors" role="alert" class="rounded-xl bg-red-50 p-5 text-sm text-red-700"><p class="font-semibold">入力内容を確認してください。</p><ul class="mt-2 list-inside list-disc"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
      <section class="rounded-xl border border-slate-200 bg-white p-6 sm:p-8"><h2 class="font-semibold">対象注文</h2><div class="mt-5"><label for="shipment-order" class="mb-2 block text-sm font-medium">注文 <span class="text-xs text-red-600">必須</span></label><select id="shipment-order" v-model="form.order_id" class="field" :disabled="!!shipment || !editable" @change="changeOrder"><option value="">選択してください</option><option v-for="order in orders" :key="order.id" :value="order.id">{{ order.order_number }}｜{{ order.customer.name }}｜{{ order.ordered_on }}</option></select></div><div v-if="selectedOrder" class="mt-4 rounded-lg bg-slate-50 p-4 text-sm"><p><strong>{{ selectedOrder.customer.name }}</strong>（{{ selectedOrder.customer.email }}）</p><p class="mt-1 text-xs text-slate-500">注文番号 {{ selectedOrder.order_number }}／注文日 {{ selectedOrder.ordered_on }}</p></div></section>
      <section class="rounded-xl border border-slate-200 bg-white"><div class="border-b border-slate-200 p-6"><h2 class="font-semibold">発送商品</h2><p class="mt-2 text-xs text-slate-500">分割発送する場合は、今回発送する数量だけを入力してください。</p></div><div class="space-y-4 p-6"><article v-for="(line, index) in form.lines" :key="line.order_line_id" class="grid items-end gap-4 rounded-xl border border-slate-200 p-5 sm:grid-cols-[1fr_12rem_auto]"><div><p class="font-semibold">{{ orderLine(line)?.product_name }}</p><p class="mt-1 text-xs text-slate-500">{{ orderLine(line)?.product_sku }}／注文 {{ number(orderLine(line)?.ordered_quantity, orderLine(line)?.quantity_scale) }} {{ orderLine(line)?.unit_name }}／今回入力前の未発送 {{ number(orderLine(line)?.remaining_quantity, orderLine(line)?.quantity_scale) }} {{ orderLine(line)?.unit_name }}</p></div><div><label :for="`shipment-quantity-${index}`" class="mb-2 block text-sm font-medium">今回の発送数量</label><div class="flex items-center gap-2"><input :id="`shipment-quantity-${index}`" v-model="line.quantity" type="number" min="0.0001" step="0.0001" class="field" :disabled="!editable" /><span class="whitespace-nowrap text-sm text-slate-500">{{ orderLine(line)?.unit_name }}</span></div></div><button v-if="editable" type="button" class="pb-3 text-sm text-red-700" @click="form.lines.splice(index, 1)">除外</button></article><p v-if="!form.lines.length" class="rounded-lg bg-slate-50 p-8 text-center text-sm text-slate-500">対象注文を選択するか、発送する商品を設定してください。</p></div></section>
      <section class="grid gap-6 rounded-xl border border-slate-200 bg-white p-6 sm:p-8 lg:grid-cols-2"><div><label for="carrier" class="mb-2 block text-sm font-medium">配送会社 <span class="text-xs text-slate-500">発送確定時必須</span></label><input id="carrier" v-model="form.carrier" class="field" maxlength="100" :disabled="!editable" placeholder="例：ヤマト運輸" /></div><div><label for="tracking-number" class="mb-2 block text-sm font-medium">追跡番号</label><input id="tracking-number" v-model="form.tracking_number" class="field" maxlength="100" :disabled="!editable" /></div><div><label for="scheduled-on" class="mb-2 block text-sm font-medium">発送予定日</label><input id="scheduled-on" v-model="form.scheduled_on" type="date" class="field" :disabled="!editable" /></div><div><label for="shipped-on" class="mb-2 block text-sm font-medium">発送日 <span class="text-xs text-slate-500">発送確定時必須</span></label><input id="shipped-on" v-model="form.shipped_on" type="date" class="field" :disabled="!editable" /></div><div class="lg:col-span-2"><label for="shipment-notes" class="mb-2 block text-sm font-medium">備考</label><textarea id="shipment-notes" v-model="form.notes" class="field min-h-24" maxlength="2000" :disabled="!editable"></textarea></div></section>
      <div v-if="editable" class="flex flex-wrap justify-end gap-3"><button type="submit" class="rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold disabled:opacity-50" :disabled="form.processing || !form.lines.length">{{ form.processing ? '保存中…' : '下書き保存' }}</button><button type="button" class="btn-primary min-w-44" :disabled="form.processing || !form.lines.length" @click="save('ship')">発送を確定</button></div>
    </form>
    <form v-if="shipment?.status === 'draft'" @submit.prevent="cancelShipment" class="mt-6 rounded-xl border border-red-100 bg-red-50 p-6"><h2 class="font-semibold text-red-900">発送を取り消す</h2><p class="mt-2 text-sm text-red-800">下書きの発送を取り消すと、数量は未発送へ戻ります。</p><div class="mt-4 flex flex-col gap-3 sm:flex-row"><input v-model="cancelForm.reason" class="field grow" maxlength="500" required placeholder="取消理由" /><button type="submit" class="rounded-lg bg-red-700 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50" :disabled="cancelForm.processing">取り消す</button></div><p v-if="cancelForm.errors.reason || cancelForm.errors.status" class="mt-2 text-sm text-red-700">{{ cancelForm.errors.reason || cancelForm.errors.status }}</p></form>
  </AdminLayout>
</template>
