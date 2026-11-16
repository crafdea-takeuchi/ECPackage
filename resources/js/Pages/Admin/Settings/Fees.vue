<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ cashOnDeliveryFee: String, cashOnDeliveryAvailable: Boolean, maxFee: String, saveUrl: String, settingsUrl: String });
const inputValue = value => value == null ? '' : String(value).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
const form = useForm({ cash_on_delivery_fee: inputValue(props.cashOnDeliveryFee) });
form.setError(usePage().props.errors ?? {});
function save() {
  if (form.processing) return;
  form.put(props.saveUrl, { preserveScroll: true, onSuccess: () => { form.defaults({ cash_on_delivery_fee: inputValue(props.cashOnDeliveryFee) }); form.reset(); } });
}
</script>

<template>
  <AdminLayout>
    <Head title="手数料設定" />
    <Link :href="settingsUrl" class="nav-link text-sm">← 各種設定へ</Link>
    <h1 class="mt-5 text-3xl font-bold">手数料設定</h1>
    <p class="mt-3 text-sm text-slate-600">支払方法に応じた固定額の手数料を設定します。</p>
    <p v-if="!cashOnDeliveryAvailable" class="mt-5 max-w-3xl rounded-lg bg-amber-50 p-4 text-sm text-amber-900">現在、代引きはご利用いただけません。保存した手数料は、現在の注文金額には加算されません。</p>
    <form class="mt-7 max-w-3xl rounded-xl border border-slate-200 bg-white p-6 sm:p-8" @submit.prevent="save" novalidate>
      <label for="cod-fee" class="mb-2 block text-sm font-medium">代引き手数料（税込）</label>
      <div class="flex max-w-sm min-w-0 items-center gap-3">
        <input id="cod-fee" v-model="form.cash_on_delivery_fee" type="number" inputmode="numeric" min="0" step="1" :max="maxFee" class="field min-w-0" :disabled="form.processing" :aria-invalid="Boolean(form.errors.cash_on_delivery_fee)" :aria-describedby="form.errors.cash_on_delivery_fee ? 'cod-fee-error' : 'cod-fee-help'" />
        <span class="shrink-0 text-sm">円</span>
      </div>
      <p v-if="form.errors.cash_on_delivery_fee" id="cod-fee-error" role="alert" class="mt-2 text-sm text-red-700">{{ form.errors.cash_on_delivery_fee }}</p>
      <p id="cod-fee-help" class="mt-3 text-xs leading-6 text-slate-500">税込の円単位で入力してください。空欄は未設定、0円は代引き手数料無料として保存します。</p>
      <p class="mt-3 text-sm text-slate-600">現在の設定：{{ cashOnDeliveryFee == null ? '未設定' : Number(cashOnDeliveryFee) === 0 ? '0円（代引き手数料無料）' : new Intl.NumberFormat('ja-JP').format(Number(cashOnDeliveryFee)) + '円（税込）' }}</p>
      <button type="submit" class="btn-primary mt-6" :disabled="form.processing">{{ form.processing ? '保存中…' : '保存' }}</button>
    </form>
  </AdminLayout>
</template>
