<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import FormField from '../../Components/FormField.vue';
const props = defineProps({ admin: Boolean });
const form = useForm({ email: '', password: '', remember: false });
function submit() { form.post(props.admin ? '/admin/login' : '/login', { onFinish: () => form.reset('password') }); }
</script>
<template>
  <AuthLayout :title="admin ? '管理者ログイン' : 'ログイン'" :admin="admin" :description="admin ? '管理者アカウントでログインしてください。' : 'メールアドレスとパスワードを入力してください。'">
    <form class="space-y-5" @submit.prevent="submit">
      <FormField id="email" v-model="form.email" label="メールアドレス" type="email" autocomplete="username" :error="form.errors.email" />
      <FormField id="password" v-model="form.password" label="パスワード" type="password" autocomplete="current-password" :error="form.errors.password" />
      <label class="flex items-center gap-2 text-sm text-slate-600"><input v-model="form.remember" type="checkbox" class="accent-emerald-800">ログイン状態を保持する</label>
      <button class="btn-primary w-full" :disabled="form.processing">{{ form.processing ? 'ログイン中…' : 'ログイン' }}</button>
    </form>
    <div class="mt-6 space-y-4 text-center text-sm"><Link href="/forgot-password" class="nav-link">パスワードをお忘れですか？</Link><!-- 新規登録停止中のため非表示 <p v-if="!admin" class="text-slate-500">初めてご利用の方は <Link href="/register" class="font-medium text-emerald-800 underline">新規登録</Link></p> --></div>
  </AuthLayout>
</template>
