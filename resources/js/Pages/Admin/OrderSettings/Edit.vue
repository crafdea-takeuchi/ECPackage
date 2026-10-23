<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
const props = defineProps({ settings: Object });
const form = useForm({ cancellable_statuses: props.settings.cancellable_statuses ?? [] });
const choices = [{ value: 'draft', label: '下書き' }, { value: 'confirmed', label: '受付済み' }, { value: 'preparing', label: '準備中' }];
</script>
<template><AdminLayout><Head title="注文設定" /><div class="mb-7"><p class="text-xs font-bold tracking-widest text-emerald-700">注文設定</p><h1 class="mt-3 text-3xl font-bold">キャンセル設定</h1><p class="mt-3 text-sm text-slate-500">管理者がキャンセルできる注文状態を選択します。キャンセル時は引き当て済み在庫を元のロットへ戻します。</p></div><form @submit.prevent="form.put('/admin/order-settings', { preserveScroll: true })" class="max-w-2xl rounded-xl border border-slate-200 bg-white p-6 sm:p-8"><h2 class="font-semibold">キャンセル可能な状態</h2><div class="mt-5 space-y-3"><label v-for="choice in choices" :key="choice.value" class="flex items-center gap-3 rounded-lg border border-slate-200 p-4"><input v-model="form.cancellable_statuses" type="checkbox" :value="choice.value" class="size-4" /><span>{{ choice.label }}</span></label></div><p v-if="form.errors.cancellable_statuses" class="mt-3 text-sm text-red-600">{{ form.errors.cancellable_statuses }}</p><p class="mt-5 text-xs leading-6 text-slate-500">発送済み・完了は返品や返金の記録が必要になるため、ここではキャンセル対象にできません。</p><button type="submit" class="btn-primary mt-7" :disabled="form.processing">{{ form.processing ? '保存中…' : '設定を保存' }}</button></form></AdminLayout></template>
