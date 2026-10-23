<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ order: Object, customers: Array, products: Array, cancellableStatuses: { type: Array, default: () => [] } });
const mapLine = line => ({ id: line.id ?? null, product_id: line.product_id ?? '', quantity: line.quantity ?? '', unit_sale_price: line.unit_sale_price ?? '' });
const form = useForm({ customer_id: props.order?.customer_id ?? '', ordered_on: props.order?.ordered_on ?? new Date().toLocaleDateString('en-CA'), notes: props.order?.notes ?? '', lock_version: props.order?.lock_version ?? 0, action: 'draft', lines: props.order?.lines?.map(mapLine) ?? [] });
const confirmed = computed(() => props.order && props.order.status !== 'draft');
const statusLabels = { draft: '下書き', confirmed: '受付済み', preparing: '準備中', shipped: '発送済み', completed: '完了', cancelled: 'キャンセル' };
const nextStatuses = { confirmed: ['preparing', '準備を開始'], shipped: ['completed', '完了にする'] };
const nextStatus = computed(() => nextStatuses[props.order?.status] ?? null);
const canCancel = computed(() => props.order && props.cancellableStatuses.includes(props.order.status));
const statusForm = useForm({ status: '' });
const cancelForm = useForm({ reason: '' });
const total = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.unit_sale_price) || 0), 0));
const money = value => new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 4 }).format(Number(value) || 0);
const productFor = line => props.products.find(product => String(product.id) === String(line.product_id));
const usedByOtherLine = (productId, index) => form.lines.some((line, lineIndex) => lineIndex !== index && String(line.product_id) === String(productId));
const makeLine = () => ({ id: null, product_id: '', quantity: '', unit_sale_price: '' });
function changeProduct(line) { line.unit_sale_price = productFor(line)?.default_sale_price ?? ''; }
function reloadFromProps() {
  if (!props.order) return;
  Object.assign(form, { customer_id: props.order.customer_id, ordered_on: props.order.ordered_on, notes: props.order.notes ?? '', lock_version: props.order.lock_version, action: 'draft', lines: props.order.lines.map(mapLine) });
  form.defaults();
}
function advanceStatus() {
  if (!nextStatus.value) return;
  statusForm.status = nextStatus.value[0];
  statusForm.patch(`/admin/orders/${props.order.id}/status`, { preserveScroll: true });
}
function cancelOrder() { cancelForm.post(`/admin/orders/${props.order.id}/cancel`, { preserveScroll: true, onSuccess: () => cancelForm.reset() }); }
function save(action) {
  form.action = action;
  const options = { preserveScroll: true, onSuccess: reloadFromProps };
  if (props.order) form.put(`/admin/orders/${props.order.id}`, options);
  else form.post('/admin/orders', options);
}
</script>

<template>
  <AdminLayout>
    <Head :title="order ? '注文を確認' : '注文を登録'" />
    <Link href="/admin/orders" class="nav-link text-sm">← 注文一覧へ</Link>
    <div class="mt-5 mb-7 flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-bold tracking-widest text-emerald-700">注文の詳細</p><h1 class="mt-3 text-3xl font-bold">{{ order ? (confirmed ? '注文の詳細' : '注文を編集') : '注文を登録' }}</h1><p class="mt-3 text-sm text-slate-500">{{ confirmed ? '確定時点の商品・価格・ロット引当を確認できます。' : '下書きでは在庫は減りません。「注文を確定」でロットを自動引当します。' }}</p></div><div v-if="order"><p class="text-xs text-slate-500">注文番号</p><p class="mt-1 font-semibold">{{ order.order_number }}</p></div></div>
    <div v-if="order" class="mb-6 rounded-xl border border-emerald-100 bg-emerald-50 p-5 text-sm text-emerald-900"><strong>現在の状態：{{ statusLabels[order.status] }}</strong><Link v-if="order.stock_operation" :href="`/admin/stock-operations/${order.stock_operation.id}`" class="ml-3 underline">出庫履歴 {{ order.stock_operation.operation_number }}</Link><Link v-if="order.cancellation_stock_operation" :href="`/admin/stock-operations/${order.cancellation_stock_operation.id}`" class="ml-3 underline">在庫戻し {{ order.cancellation_stock_operation.operation_number }}</Link></div><section v-if="order && order.status !== 'draft'" class="mb-6 rounded-xl border border-slate-200 bg-white p-6"><h2 class="font-semibold">進捗を変更</h2><div class="mt-4 flex flex-wrap items-center gap-3"><button v-if="nextStatus" type="button" class="btn-primary" :disabled="statusForm.processing" @click="advanceStatus">{{ nextStatus[1] }}</button><span v-else-if="order.status === 'preparing'" class="text-sm text-slate-500">発送管理で発送を確定すると、自動で発送済みになります。</span><span v-else class="text-sm text-slate-500">次の状態への変更はありません。</span></div></section><section v-if="order && order.status !== 'draft'" class="mb-6 rounded-xl border border-slate-200 bg-white p-6"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold">発送状況</h2><p class="mt-2 text-xs text-slate-500">分割発送にも対応しています。</p></div><Link v-if="['confirmed', 'preparing'].includes(order.status)" :href="`/admin/shipments/create?order_id=${order.id}`" class="btn-primary">＋ この注文の発送を登録</Link></div><div v-if="order.shipments?.length" class="mt-5 divide-y divide-slate-100 rounded-lg border border-slate-200"><Link v-for="shipment in order.shipments" :key="shipment.id" :href="`/admin/shipments/${shipment.id}/edit`" class="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-slate-50"><div><p class="font-semibold text-emerald-900">{{ shipment.shipment_number }}</p><p class="mt-1 text-xs text-slate-500">{{ shipment.carrier || '配送会社未設定' }}／{{ shipment.tracking_number || '追跡番号なし' }}</p></div><div class="text-right text-sm"><p>{{ shipment.status === 'shipped' ? '発送済み' : shipment.status === 'cancelled' ? '取消' : '発送準備中' }}</p><p class="mt-1 text-xs text-slate-500">{{ shipment.shipped_on || shipment.scheduled_on || '日付未設定' }}</p></div></Link></div><p v-else class="mt-5 rounded-lg bg-slate-50 p-5 text-sm text-slate-500">発送はまだ登録されていません。</p></section><form v-if="canCancel" @submit.prevent="cancelOrder" class="mb-6 rounded-xl border border-red-100 bg-red-50 p-6"><h2 class="font-semibold text-red-900">注文をキャンセル</h2><p class="mt-2 text-sm text-red-800">引き当て済みの数量は、出庫元と同じロットへ自動で戻ります。</p><div class="mt-4 flex flex-col gap-3 sm:flex-row"><input v-model="cancelForm.reason" class="field grow" maxlength="500" required placeholder="キャンセル理由" /><button type="submit" class="rounded-lg bg-red-700 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50" :disabled="cancelForm.processing">キャンセルする</button></div><p v-if="cancelForm.errors.reason || cancelForm.errors.status" class="mt-2 text-sm text-red-700">{{ cancelForm.errors.reason || cancelForm.errors.status }}</p></form>
    <form @submit.prevent="save('draft')" class="space-y-6" novalidate>
      <div v-if="form.hasErrors" role="alert" class="rounded-xl bg-red-50 p-5 text-sm text-red-700"><p class="font-semibold">入力内容を確認してください。</p><ul class="mt-2 list-inside list-disc"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
      <section class="grid gap-6 rounded-xl border border-slate-200 bg-white p-6 sm:p-8 lg:grid-cols-2">
        <div><label for="order-customer" class="mb-2 block text-sm font-medium">お客様 <span class="text-xs text-red-600">必須</span></label><select id="order-customer" v-model="form.customer_id" class="field" :disabled="confirmed"><option value="">選択してください</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}｜{{ customer.email }}</option></select><p v-if="!customers.length" class="mt-2 text-xs text-amber-700">お客様アカウントを先に作成してください。</p></div>
        <div><label for="ordered-on" class="mb-2 block text-sm font-medium">注文日 <span class="text-xs text-red-600">必須</span></label><input id="ordered-on" v-model="form.ordered_on" type="date" class="field" :disabled="confirmed" /></div>
        <div class="lg:col-span-2"><label for="order-notes" class="mb-2 block text-sm font-medium">備考</label><textarea id="order-notes" v-model="form.notes" class="field min-h-20" maxlength="2000" :disabled="confirmed"></textarea></div>
      </section>
      <section class="rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-6"><div><h2 class="font-semibold">注文商品</h2><p class="mt-2 text-xs text-slate-500">確定時は期限日が近いロット、次に入庫日が古いロットから自動出庫します。</p></div><button v-if="!confirmed" type="button" @click="form.lines.push(makeLine())" :disabled="form.lines.length >= 100" class="rounded-lg border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 disabled:opacity-40">＋ 商品を追加</button></div>
        <div class="space-y-4 p-6">
          <article v-for="(line, index) in form.lines" :key="line.id ?? `new-${index}`" class="rounded-xl border border-slate-200 p-5"><div class="mb-4 flex items-center justify-between"><h3 class="font-semibold">明細 {{ index + 1 }}</h3><button v-if="!confirmed" type="button" @click="form.lines.splice(index, 1)" class="text-sm text-red-700">取り除く</button></div>
            <div class="grid gap-4 lg:grid-cols-12">
              <div class="lg:col-span-5"><label :for="`order-product-${index}`" class="mb-2 block text-sm font-medium">販売商品 <span class="text-xs text-red-600">必須</span></label><select :id="`order-product-${index}`" v-model="line.product_id" class="field" :disabled="confirmed" @change="changeProduct(line)"><option value="">選択してください</option><option v-for="product in products" :key="product.id" :value="product.id" :disabled="usedByOtherLine(product.id, index)">{{ product.sku }}｜{{ product.name }}｜在庫 {{ money(product.available_quantity) }} {{ product.unit.name }}</option></select></div>
              <div class="lg:col-span-2"><label :for="`order-quantity-${index}`" class="mb-2 block text-sm font-medium">数量 <span class="text-xs text-red-600">必須</span></label><div class="flex items-center gap-2"><input :id="`order-quantity-${index}`" v-model="line.quantity" type="number" min="0.0001" step="0.0001" class="field" :disabled="confirmed" /><span class="min-w-8 text-sm text-slate-500">{{ productFor(line)?.unit?.name ?? '' }}</span></div></div>
              <div class="lg:col-span-2"><label :for="`order-price-${index}`" class="mb-2 block text-sm font-medium">販売単価 <span class="text-xs text-red-600">必須</span></label><input :id="`order-price-${index}`" v-model="line.unit_sale_price" type="number" min="0" step="0.0001" class="field" :disabled="confirmed" /></div>
              <div class="lg:col-span-3"><p class="mb-2 text-sm font-medium">商品金額</p><p class="rounded-lg bg-slate-50 px-4 py-3 text-right font-semibold tabular-nums">{{ money((Number(line.quantity) || 0) * (Number(line.unit_sale_price) || 0)) }}</p></div>
            </div>
            <div v-if="confirmed && order.lines[index]?.components?.length" class="mt-4 rounded-lg border border-blue-100 bg-blue-50/50 p-4"><p class="text-xs font-semibold text-blue-900">注文確定時のセット内容</p><ul class="mt-2 space-y-1 text-sm text-blue-950"><li v-for="component in order.lines[index].components" :key="component.id">{{ component.component_name }}：{{ money(component.quantity_per_set) }} {{ component.unit_name }} × {{ money(order.lines[index].quantity) }} セット（必要数 {{ money(component.required_quantity) }} {{ component.unit_name }}）</li></ul></div><div v-if="confirmed && order.lines[index]?.backorders?.length" class="mt-3 space-y-1"><p v-for="backorder in order.lines[index].backorders" :key="backorder.id" class="text-sm font-semibold" :class="Number(backorder.remaining_quantity) > 0 ? 'text-amber-700' : 'text-emerald-700'">{{ backorder.stock_product_name }}：{{ Number(backorder.remaining_quantity) > 0 ? `取り寄せ残 ${money(backorder.remaining_quantity)} ${backorder.unit_name}` : '取り寄せ分は入荷・引当済みです' }}</p></div><p v-if="confirmed && !order.lines[index]?.components?.length && Number(order.lines[index]?.backordered_quantity) > 0" class="mt-3 text-sm font-semibold text-amber-700">取り寄せ残：{{ money(order.lines[index].backordered_quantity) }} {{ order.lines[index].unit_name }}</p><p v-else-if="confirmed && !order.lines[index]?.components?.length && Number(order.lines[index]?.backordered_initial_quantity) > 0" class="mt-3 text-sm font-semibold text-emerald-700">取り寄せ分は入荷・引当済みです。</p><div v-if="confirmed && order.lines[index]?.allocations?.length" class="mt-4 rounded-lg bg-slate-50 p-4"><p class="text-xs font-semibold text-slate-500">引当ロット</p><div class="mt-2 flex flex-wrap gap-2"><span v-for="allocation in order.lines[index].allocations" :key="allocation.id" class="rounded-full bg-white px-3 py-1 text-xs"><span v-if="allocation.order_line_component" class="font-semibold">{{ allocation.order_line_component.component_name }}｜</span>{{ allocation.inventory_lot.lot_number }}：{{ money(allocation.quantity) }} {{ allocation.order_line_component?.unit_name ?? order.lines[index].unit_name }}</span></div></div>
          </article>
          <div v-if="!form.lines.length" class="rounded-lg bg-slate-50 p-8 text-center text-sm text-slate-500">「商品を追加」から注文商品を追加してください。</div>
        </div>
        <div class="flex items-center justify-between border-t border-slate-200 p-6"><span class="text-sm text-slate-500">注文金額</span><strong class="text-xl tabular-nums">{{ money(total) }}</strong></div>
      </section>
      <div v-if="!confirmed" class="flex flex-wrap justify-end gap-3"><button type="submit" class="rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold disabled:opacity-50" :disabled="form.processing">{{ form.processing ? '保存中…' : '下書き保存' }}</button><button type="button" @click="save('confirm')" class="btn-primary min-w-48" :disabled="form.processing">{{ form.processing ? '処理中…' : '注文を確定' }}</button></div>
    </form>
  </AdminLayout>
</template>
