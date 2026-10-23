<script setup>
import { Link } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import AccountForm from '../../Components/AccountForm.vue';
</script>
<template><AuthLayout title="アカウントを作成" description="お客様または管理者のアカウントを追加します。" admin><AccountForm admin /><p class="mt-6 text-center text-sm"><Link href="/admin" class="nav-link">管理画面に戻る</Link></p></AuthLayout></template>
