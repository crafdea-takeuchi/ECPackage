<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ googleAnalyticsId: String, saveUrl: String, settingsUrl: String });
const form = useForm({ google_analytics_id: props.googleAnalyticsId ?? '' });
form.setError(usePage().props.errors ?? {});
function save() {
  if (form.processing) return;
  form.put(props.saveUrl, {
    preserveScroll: true,
    onSuccess: () => { form.defaults({ google_analytics_id: props.googleAnalyticsId ?? '' }); form.reset(); },
  });
}
</script>

<template>
  <AdminLayout>
    <Head title="Googleアナリティクス設定" />
    <Link :href="settingsUrl" class="nav-link text-sm">← 各種設定へ</Link>
    <h1 class="mt-5 text-3xl font-bold">Googleアナリティクス設定</h1>
    <p class="mt-3 text-sm text-slate-600">お客様向け画面（ホーム・商品一覧・カート・購入手続きなど）のアクセスをGoogleアナリティクス（GA4）で計測します。</p>
    <form class="mt-7 max-w-3xl rounded-xl border border-slate-200 bg-white p-6 sm:p-8" novalidate @submit.prevent="save">
      <label for="google-analytics-id" class="mb-2 block text-sm font-medium">測定ID <span class="ml-2 text-xs font-normal text-slate-500">任意</span></label>
      <input
        id="google-analytics-id"
        v-model="form.google_analytics_id"
        type="text"
        maxlength="20"
        autocomplete="off"
        placeholder="例：G-ABC123XYZ9"
        class="field max-w-xs"
        :disabled="form.processing"
        :aria-invalid="Boolean(form.errors.google_analytics_id)"
        :aria-describedby="form.errors.google_analytics_id ? 'google-analytics-id-error' : 'google-analytics-id-help'"
      />
      <p v-if="form.errors.google_analytics_id" id="google-analytics-id-error" role="alert" class="mt-2 text-sm text-red-700">{{ form.errors.google_analytics_id }}</p>
      <div id="google-analytics-id-help" class="mt-3 space-y-1 text-xs leading-6 text-slate-500">
        <p>Googleアナリティクスの「管理 → データストリーム」に表示される「G-」から始まる測定IDを入力してください。</p>
        <p>空欄で保存すると計測を停止します。管理画面と、管理者アカウントでログイン中のアクセスは計測しません。</p>
      </div>
      <button type="submit" class="btn-primary mt-6" :disabled="form.processing">{{ form.processing ? '保存中…' : '保存' }}</button>
    </form>
  </AdminLayout>
</template>
