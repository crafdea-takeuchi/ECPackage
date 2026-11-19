<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import PostalCodeShippingGroups from '../../../Components/Admin/PostalCodeShippingGroups.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ zones: Array, shippingZoneFees: Object, freeShippingThresholds: Object, postalCodeGroups: { type: Array, default: () => [] }, postalCodeGroupStoreUrl: String, maxAmount: String, saveUrl: String, settingsUrl: String });
const inputValue = value => value == null ? '' : String(value).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
const values = () => ({
  shipping_zone_fees: Object.fromEntries(props.zones.map(zone => [zone.code, inputValue(props.shippingZoneFees?.[zone.code])])),
  free_shipping_thresholds: Object.fromEntries(props.zones.map(zone => [zone.code, inputValue(props.freeShippingThresholds?.[zone.code])])),
});
const form = useForm(values());
form.setError(usePage().props.errors ?? {});
const zoneError = code => form.errors['shipping_zone_fees.' + code];
const thresholdError = code => form.errors['free_shipping_thresholds.' + code];
function save() {
  if (form.processing) return;
  form.put(props.saveUrl, { preserveScroll: true, onSuccess: () => { form.defaults(values()); form.reset(); } });
}
</script>

<template>
  <AdminLayout>
    <Head title="送料設定" />
    <Link :href="settingsUrl" class="nav-link text-sm">← 各種設定へ</Link>
    <h1 class="mt-5 text-3xl font-bold">送料設定</h1>
    <p class="mt-3 text-sm text-slate-600">発送先の地域ごとの送料と、送料無料になる購入金額を設定します。</p>
    <form class="mt-7 max-w-6xl rounded-xl border border-slate-200 bg-white p-5 sm:p-8" @submit.prevent="save" novalidate>
      <h2 class="mb-3 text-lg font-semibold">地域ごとの送料</h2>
      <p class="mb-5 text-sm text-slate-600">8地域すべてに税込・円単位の整数を入力してください。0円は送料無料として保存します。初回の空欄は未設定です。</p>
      <p v-if="form.errors.shipping_zone_fees" role="alert" class="mb-4 text-sm text-red-700">{{ form.errors.shipping_zone_fees }}</p>
      <p v-if="form.errors.free_shipping_thresholds" role="alert" class="mb-4 text-sm text-red-700">{{ form.errors.free_shipping_thresholds }}</p>
      <table class="block w-full table-fixed text-left text-sm xl:table">
        <thead class="hidden bg-slate-50 text-slate-600 xl:table-header-group"><tr><th scope="col" class="w-32 px-4 py-3">送料区分</th><th scope="col" class="px-4 py-3">対象の都道府県</th><th scope="col" class="w-48 px-4 py-3">送料（税込）</th><th scope="col" class="w-56 px-4 py-3">送料無料になる購入金額（税込）</th></tr></thead>
        <tbody class="block space-y-4 xl:table-row-group xl:space-y-0">
          <tr v-for="zone in zones" :key="zone.code" class="block rounded-lg border border-slate-200 xl:table-row xl:rounded-none xl:border-x-0">
            <th scope="row" class="block px-4 pt-4 font-semibold xl:table-cell xl:py-4">{{ zone.name }}</th>
            <td class="block break-words px-4 pt-2 text-slate-600 xl:table-cell xl:py-4"><span class="mb-1 block text-xs xl:hidden">対象の都道府県</span>{{ zone.prefectures.join('・') }}</td>
            <td class="block px-4 py-4 xl:table-cell">
              <label :for="'shipping-' + zone.code" class="mb-2 block text-xs font-medium xl:sr-only">{{ zone.name }}の送料（税込）</label>
              <div class="flex max-w-xs min-w-0 items-center gap-2">
                <input :id="'shipping-' + zone.code" v-model="form.shipping_zone_fees[zone.code]" type="number" inputmode="numeric" min="0" step="1" :max="maxAmount" required class="field min-w-0" :disabled="form.processing" :aria-invalid="Boolean(zoneError(zone.code))" :aria-describedby="zoneError(zone.code) ? 'error-' + zone.code : undefined" />
                <span class="shrink-0">円</span>
              </div>
              <p v-if="zoneError(zone.code)" :id="'error-' + zone.code" role="alert" class="mt-2 text-sm text-red-700">{{ zoneError(zone.code) }}</p>
            </td>
            <td class="block px-4 py-4 xl:table-cell">
              <label :for="'free-shipping-' + zone.code" class="mb-2 block text-xs font-medium xl:sr-only">{{ zone.name }}の送料無料になる購入金額（税込）</label>
              <div class="flex max-w-xs min-w-0 items-center gap-2">
                <input :id="'free-shipping-' + zone.code" v-model="form.free_shipping_thresholds[zone.code]" type="number" inputmode="numeric" min="0" step="1" :max="maxAmount" class="field min-w-0" :disabled="form.processing" :aria-invalid="Boolean(thresholdError(zone.code))" :aria-describedby="thresholdError(zone.code) ? 'threshold-error-' + zone.code : undefined" />
                <span class="shrink-0 text-sm">円以上</span>
              </div>
              <p v-if="thresholdError(zone.code)" :id="'threshold-error-' + zone.code" role="alert" class="mt-2 text-sm text-red-700">{{ thresholdError(zone.code) }}</p>
            </td>
          </tr>
        </tbody>
      </table>
      <button type="submit" class="btn-primary mt-6" :disabled="form.processing">{{ form.processing ? '保存中…' : '保存' }}</button>
    </form>
    <PostalCodeShippingGroups :groups="postalCodeGroups" :store-url="postalCodeGroupStoreUrl" :max-amount="maxAmount" />
  </AdminLayout>
</template>
