<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ bank: Object, registered: Boolean, fields: Object, accountTypes: Array, saveUrl: String, settingsUrl: String, updatedAt: String });
const values = () => Object.fromEntries(Object.keys(props.fields).map(key => [key, props.bank?.[key] ?? '']));
const form = useForm(values());
form.setError(usePage().props.errors ?? {});
const optional = ['bank_code', 'branch_code', 'instructions'];
const numeric = ['bank_code', 'branch_code', 'account_number'];
const maxLength = key => key === 'instructions' ? 2000 : key === 'account_number' ? 20 : ['bank_code', 'branch_code'].includes(key) ? 10 : 100;
function save() {
  form.put(props.saveUrl, { preserveScroll: true, onSuccess: () => { form.defaults(values()); form.reset(); } });
}
</script>

<template>
  <AdminLayout>
    <Head title="振込先設定" />
    <Link :href="settingsUrl" class="nav-link text-sm">← 各種設定へ</Link>
    <h1 class="mt-5 text-3xl font-bold">振込先設定</h1>
    <p class="mt-3 text-sm text-slate-600">{{ registered ? '登録済みの振込先を更新します。過去注文の振込先は変更されません。' : '振込先を登録します。銀行振込の注文を受け付ける前に設定してください。' }}</p>
    <form class="mt-7 max-w-3xl rounded-xl border border-slate-200 bg-white p-6 sm:p-8" @submit.prevent="save" novalidate>
      <div class="grid min-w-0 gap-5 sm:grid-cols-2">
        <div v-for="(label, key) in fields" :key="key" class="min-w-0" :class="key === 'instructions' ? 'sm:col-span-2' : ''">
          <label :for="'bank-' + key" class="mb-2 block text-sm font-medium">{{ label }} <span v-if="!optional.includes(key)" class="text-xs text-red-600">必須</span></label>
          <select v-if="key === 'account_type'" :id="'bank-' + key" v-model="form[key]" class="field" :disabled="form.processing" :aria-invalid="Boolean(form.errors[key])" :aria-describedby="form.errors[key] ? 'error-' + key : undefined"><option value="">選択してください</option><option v-for="type in accountTypes" :key="type" :value="type">{{ type }}</option></select>
          <textarea v-else-if="key === 'instructions'" :id="'bank-' + key" v-model="form[key]" class="field min-h-28" :maxlength="maxLength(key)" :disabled="form.processing" :aria-invalid="Boolean(form.errors[key])" :aria-describedby="form.errors[key] ? 'error-' + key : undefined"></textarea>
          <input v-else :id="'bank-' + key" v-model="form[key]" type="text" :inputmode="numeric.includes(key) ? 'numeric' : undefined" :maxlength="maxLength(key)" class="field" :disabled="form.processing" :aria-invalid="Boolean(form.errors[key])" :aria-describedby="form.errors[key] ? 'error-' + key : undefined" />
          <p v-if="form.errors[key]" :id="'error-' + key" role="alert" class="mt-2 text-sm text-red-700">{{ form.errors[key] }}</p>
        </div>
      </div>
      <p class="mt-5 text-xs text-slate-500">銀行コード・支店コード・口座番号は文字列として保存し、先頭の0を保持します。全角数字は半角へ変換します。</p>
      <p v-if="updatedAt" class="mt-3 text-xs text-slate-500">最終更新：{{ updatedAt }}（日本時間）</p>
      <button type="submit" class="btn-primary mt-6" :disabled="form.processing">{{ form.processing ? '保存中…' : '保存' }}</button>
    </form>
  </AdminLayout>
</template>
