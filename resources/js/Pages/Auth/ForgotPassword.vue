<script setup>
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import FormField from '../../Components/FormField.vue';
const form = useForm({ email: '' });
</script>
<template><AuthLayout title="パスワードをお忘れの方" description="登録したメールアドレスに、パスワード再設定用のリンクをお送りします。"><form class="space-y-6" @submit.prevent="form.post('/forgot-password')"><FormField id="email" v-model="form.email" label="メールアドレス" type="email" autocomplete="email" :error="form.errors.email" /><button class="btn-primary w-full" :disabled="form.processing">{{ form.processing ? '送信中…' : '再設定メールを送信' }}</button></form></AuthLayout></template>
