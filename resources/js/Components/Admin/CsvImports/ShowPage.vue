<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({
  context: Object,
  import: Object,
  preview: Object,
  errors: Array,
  definition_available: Boolean,
  can_execute: Boolean,
});

const page = usePage();
const csvImport = computed(() => props.import);
let polling;

const statusClass = {
  uploaded: 'bg-blue-50 text-blue-800',
  validating: 'bg-blue-50 text-blue-800',
  ready: 'bg-emerald-50 text-emerald-800',
  invalid: 'bg-amber-50 text-amber-800',
  importing: 'bg-blue-50 text-blue-800',
  completed: 'bg-emerald-50 text-emerald-800',
  failed: 'bg-red-50 text-red-800',
};

function executeImport() {
  if (confirm('検証済みのCSVを実際のデータへ登録します。実行しますか？')) {
    router.post(props.context.base_url + '/' + csvImport.value.id + '/execute');
  }
}

watch(() => props.import.is_processing, (isProcessing) => {
  window.clearInterval(polling);
  if (isProcessing) {
    polling = window.setInterval(() => router.reload({ only: ['context', 'import', 'preview', 'errors', 'definition_available', 'can_execute'] }), 3000);
  }
}, { immediate: true });

onBeforeUnmount(() => window.clearInterval(polling));
</script>

<template>
  <AdminLayout>
    <Head :title="context.title + ' #' + csvImport.id" />
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <Link :href="context.base_url" class="nav-link">← {{ context.title }}履歴へ</Link>
        <p class="mt-6 text-xs font-bold tracking-widest text-emerald-700">{{ context.parent_label }} / CSV取込 #{{ csvImport.id }}</p>
        <h1 class="mt-3 break-all text-2xl font-bold">{{ csvImport.original_filename }}</h1>
      </div>
      <span class="rounded-full px-4 py-2 text-sm font-semibold" :class="statusClass[csvImport.status]">{{ csvImport.status_label }}</span>
    </div>

    <div v-if="page.props.errors.csv" class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ page.props.errors.csv }}</div>
    <div v-if="csvImport.is_processing" class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">処理中です。この画面は自動的に更新されます。</div>
    <div v-if="!definition_available && csvImport.status === 'ready'" class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">CSVの文字コード・ヘッダー・列数の共通検証は完了しました。この管理機能の業務データ登録定義はまだ追加されていないため、実登録はできません。</div>
    <div v-if="csvImport.system_error" class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p class="font-semibold">システムエラー</p><p class="mt-2 break-all">{{ csvImport.system_error }}</p></div>

    <section class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">取込対象</p><p class="mt-2 font-semibold">{{ context.type_label }}</p></div>
      <div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">文字コード</p><p class="mt-2 font-semibold">{{ csvImport.encoding ?? '確認中' }}</p></div>
      <div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">データ行</p><p class="mt-2 text-2xl font-semibold">{{ csvImport.total_rows }}</p></div>
      <div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-xs text-slate-500">エラー行</p><p class="mt-2 text-2xl font-semibold" :class="csvImport.error_rows ? 'text-amber-700' : ''">{{ csvImport.error_rows }}</p></div>
    </section>

    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-5 text-sm">
      <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-slate-500">登録者</dt><dd class="mt-1 font-medium">{{ csvImport.creator?.name }}</dd></div>
        <div><dt class="text-slate-500">受付日時</dt><dd class="mt-1 font-medium">{{ csvImport.created_at }}</dd></div>
        <div><dt class="text-slate-500">正常行</dt><dd class="mt-1 font-medium">{{ csvImport.valid_rows }}</dd></div>
        <div><dt class="text-slate-500">登録済み行</dt><dd class="mt-1 font-medium">{{ csvImport.imported_rows }}</dd></div>
      </dl>
      <div v-if="can_execute" class="mt-5 border-t border-slate-200 pt-5"><button type="button" class="btn-primary" @click="executeImport">CSV取込を実行</button><p class="mt-2 text-xs text-slate-500">全行を一括登録します。途中で失敗した場合は全件を取り消します。</p></div>
    </section>

    <section v-if="errors.length" class="mt-7 overflow-hidden rounded-xl border border-amber-200 bg-white">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-5 py-4">
        <p class="font-semibold text-amber-900">検証エラー（先頭100件）</p>
        <a :href="context.base_url + '/' + csvImport.id + '/errors.csv'" class="nav-link">エラーCSVをダウンロード</a>
      </div>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-3">行</th><th class="px-5 py-3">列</th><th class="px-5 py-3">内容</th></tr></thead><tbody><tr v-for="error in errors" :key="error.id" class="border-t border-slate-100"><td class="px-5 py-3">{{ error.row_number }}</td><td class="px-5 py-3">{{ error.column ?? '—' }}</td><td class="px-5 py-3">{{ error.message }}</td></tr></tbody></table></div>
    </section>

    <section v-if="preview" class="mt-7 overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="border-b border-slate-200 px-5 py-4"><p class="font-semibold">プレビュー</p><p class="mt-1 text-xs text-slate-500">先頭{{ preview.rows.length }}行を表示しています。</p></div>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-3">行</th><th v-for="header in preview.headers" :key="header" class="whitespace-nowrap px-4 py-3">{{ header }}</th></tr></thead><tbody><tr v-for="row in preview.rows" :key="row.row_number" class="border-t border-slate-100"><td class="px-4 py-3 text-slate-500">{{ row.row_number }}</td><td v-for="header in preview.headers" :key="header" class="max-w-xs whitespace-nowrap px-4 py-3">{{ row.data[header] }}</td></tr></tbody></table></div>
    </section>
  </AdminLayout>
</template>