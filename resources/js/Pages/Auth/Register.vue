<script setup>
import { Link } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import AccountForm from '../../Components/AccountForm.vue';
</script>
<template><AuthLayout title="新規登録" description="登録後、メールアドレスの確認をお願いします。"><AccountForm /><p class="mt-6 text-center text-sm text-slate-500">登録済みの方は <Link href="/login" class="text-emerald-800 underline">ログイン</Link></p></AuthLayout></template>
