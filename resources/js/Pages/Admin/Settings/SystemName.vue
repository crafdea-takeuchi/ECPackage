<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ systemName: String, saveUrl: String, settingsUrl: String });
const form = useForm({ system_name: props.systemName ?? '' });
form.setError(usePage().props.errors ?? {});
function save() {
  if (form.processing) return;
  form.put(props.saveUrl, {
    preserveScroll: true,
    onSuccess: () => { form.defaults({ system_name: props.systemName }); form.reset(); },
  });
}
</script>

<template>
  <AdminLayout>
    <Head title="システム名設定" />
    <Link :href="settingsUrl" class="nav-link text-sm">← 各種設定へ</Link>
    <h1 class="mt-5 text-3xl font-bold">システム名設定</h1>
    <p class="mt-3 text-sm text-slate-600">画面と通知メールに表示するシステム名を設定します。</p>
    <form class="mt-7 max-w-3xl rounded-xl border border-slate-200 bg-white p-6 sm:p-8" novalidate @submit.prevent="save">
      <label for="system-name" class="mb-2 block text-sm font-medium">システム名</label>
      <input
        id="system-name"
        v-model="form.system_name"
        type="text"
        maxlength="100"
        class="field max-w-lg"
        :disabled="form.processing"
        :aria-invalid="Boolean(form.errors.system_name)"
        :aria-describedby="form.errors.system_name ? 'system-name-error' : 'system-name-help'"
      />
      <p v-if="form.errors.system_name" id="system-name-error" role="alert" class="mt-2 text-sm text-red-700">{{ form.errors.system_name }}</p>
      <p id="system-name-help" class="mt-3 text-xs leading-6 text-slate-500">100文字以内の1行で入力してください。保存後、画面のヘッダーや通知メールへ反映されます。</p>
      <button type="submit" class="btn-primary mt-6" :disabled="form.processing">{{ form.processing ? '保存中…' : '保存' }}</button>
    </form>
  </AdminLayout>
</template>
