<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
const form = useForm({});
const page = usePage();
</script>
<template><AuthLayout title="メールをご確認ください" description="メールに記載されたリンクを開くと、メールアドレスの確認が完了します。"><p class="mb-6 rounded-lg bg-slate-50 p-4 text-sm break-all">送信先：{{ page.props.auth.user.email }}</p><button class="btn-primary w-full" :disabled="form.processing" @click="form.post('/email/verification-notification')">{{ form.processing ? '送信中…' : '確認メールを再送信' }}</button><p class="mt-6 text-center text-sm"><Link href="/logout" method="post" as="button" class="nav-link">ログアウトして別のアカウントを使う</Link></p></AuthLayout></template>
