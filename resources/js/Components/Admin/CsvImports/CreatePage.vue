<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

const props = defineProps({ context: Object, limits: Object });
const form = useForm({ file: null });

function submit() {
  form.post(props.context.base_url, { forceFormData: true });
}
</script>

<template>
  <AdminLayout>
    <Head :title="context.title + ' - アップロード'" />
    <div class="mb-7">
      <Link :href="context.base_url" class="nav-link">← {{ context.title }}履歴へ</Link>
      <p class="mt-6 text-xs font-bold tracking-widest text-emerald-700">{{ context.parent_label }}</p>
      <h1 class="mt-3 text-3xl font-bold">{{ context.title }}</h1>
      <p class="mt-3 text-sm text-slate-500">先に全行を検証し、内容を確認してから実登録します。</p>
    </div>

    <form class="max-w-2xl space-y-6 rounded-xl border border-slate-200 bg-white p-6" @submit.prevent="submit">
      <div class="rounded-lg bg-slate-50 p-4 text-sm">
        <p class="text-xs text-slate-500">取込対象</p>
        <p class="mt-1 font-semibold">{{ context.type_label }}</p>
      </div>

      <div>
        <label for="csv-file" class="mb-2 block text-sm font-semibold">CSVファイル <span class="text-red-600">*</span></label>
        <input id="csv-file" type="file" accept=".csv,text/csv" class="field file:mr-4 file:rounded-md file:border-0 file:bg-emerald-50 file:px-4 file:py-2 file:font-semibold file:text-emerald-800 hover:file:bg-emerald-100" required @change="form.file = $event.target.files[0] ?? null" />
        <p v-if="form.errors.file" class="mt-2 text-sm text-red-700">{{ form.errors.file }}</p>
      </div>

      <div v-if="context.help.length" class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-900">
        <p class="font-semibold">{{ context.type_label }}CSVの入力方法</p>
        <ul class="mt-2 list-disc pl-5"><li v-for="item in context.help" :key="item">{{ item }}</li></ul>
      </div>

      <div v-if="!context.template_available" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
        この管理機能の業務別登録定義はまだ未実装です。現在はCSVの文字コード・ヘッダー・列数の共通検証まで利用できます。
      </div>

      <div class="rounded-lg bg-slate-50 p-4 text-sm leading-6 text-slate-600">
        <p class="font-semibold text-slate-800">対応形式</p>
        <ul class="mt-2 list-disc pl-5">
          <li>文字コード: UTF-8、UTF-8 BOM、CP932（ExcelのCSV）</li>
          <li>上限: {{ limits.file_size_mb }}MB、{{ limits.rows.toLocaleString() }}データ行</li>
          <li>ファイルは非公開領域に保存されます。</li>
        </ul>
      </div>

      <div class="flex items-center gap-4">
        <button type="submit" class="btn-primary" :disabled="form.processing" :class="{ 'opacity-50': form.processing }">{{ form.processing ? '送信中…' : 'アップロードして検証' }}</button>
        <Link :href="context.base_url" class="nav-link">キャンセル</Link>
      </div>
    </form>

    <section v-if="context.fields.length" class="mt-8 overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
        <div>
          <h2 class="text-lg font-bold">CSVひな形・項目説明</h2>
          <p class="mt-1 text-sm text-slate-500">ひな形の列名と順番は変更せず、2行目以降にデータを入力してください。</p>
        </div>
        <a
          v-if="context.template_available"
          :href="context.base_url + '/template'"
          class="inline-flex items-center rounded-lg border border-emerald-700 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
        >CSVひな形をダウンロード</a>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[52rem] text-left text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="w-48 px-5 py-3">項目名</th>
              <th class="w-32 px-5 py-3">入力区分</th>
              <th class="px-5 py-3">説明</th>
              <th class="w-52 px-5 py-3">入力例</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="field in context.fields" :key="field.name" class="border-t border-slate-100 align-top">
              <th scope="row" class="px-5 py-4 font-semibold text-slate-900">{{ field.name }}</th>
              <td class="px-5 py-4">
                <span
                  class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold"
                  :class="field.requirement === '任意' ? 'bg-slate-100 text-slate-600' : (field.requirement === '必須' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800')"
                >{{ field.requirement }}</span>
              </td>
              <td class="px-5 py-4 leading-6 text-slate-600">{{ field.description }}</td>
              <td class="px-5 py-4 font-mono text-xs text-slate-700">{{ field.example }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </AdminLayout>
</template>