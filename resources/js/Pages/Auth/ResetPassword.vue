<script setup>
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import FormField from '../../Components/FormField.vue';
const props = defineProps({ token: String, email: String });
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
</script>
<template><AuthLayout title="パスワード再設定" description="新しいパスワードを設定してください。"><form class="space-y-5" @submit.prevent="form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })"><FormField id="email" v-model="form.email" label="メールアドレス" type="email" autocomplete="username" :error="form.errors.email" /><FormField id="password" v-model="form.password" label="新しいパスワード" type="password" autocomplete="new-password" hint="英字と数字を含む12文字以上" :error="form.errors.password" /><FormField id="password_confirmation" v-model="form.password_confirmation" label="パスワード（確認）" type="password" autocomplete="new-password" /><p v-if="form.errors.token" role="alert" class="text-sm text-red-700">{{ form.errors.token }}</p><button class="btn-primary w-full" :disabled="form.processing">パスワードを変更</button></form></AuthLayout></template>
