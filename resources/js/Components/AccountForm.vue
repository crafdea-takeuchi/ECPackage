<script setup>
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
const props = defineProps({ admin: Boolean });
const form = useForm({ name: '', email: '', password: '', password_confirmation: '', ...(props.admin ? { role: 'customer' } : {}) });
function submit() { form.post(props.admin ? '/admin/users' : '/register', { onFinish: () => form.reset('password', 'password_confirmation') }); }
</script>
<template>
  <form class="space-y-5" @submit.prevent="submit">
    <FormField id="name" v-model="form.name" label="お名前" autocomplete="name" :error="form.errors.name" />
    <FormField id="email" v-model="form.email" label="メールアドレス" type="email" autocomplete="email" :error="form.errors.email" />
    <div v-if="admin"><label for="role" class="mb-2 block text-sm font-medium">権限</label><select id="role" v-model="form.role" class="field"><option value="customer">お客様</option><option value="admin">管理者</option></select><p v-if="form.errors.role" role="alert" class="mt-2 text-sm text-red-700">{{ form.errors.role }}</p></div>
    <FormField id="password" v-model="form.password" label="パスワード" type="password" autocomplete="new-password" hint="英字と数字を含む12文字以上で設定してください。" :error="form.errors.password" />
    <FormField id="password_confirmation" v-model="form.password_confirmation" label="パスワード（確認）" type="password" autocomplete="new-password" />
    <p v-if="admin" class="text-xs leading-6 text-slate-500">作成後、本人宛にメールアドレスの確認メールを送信します。設定したパスワードは安全な方法で本人に伝えてください。</p>
    <button :disabled="form.processing" class="btn-primary w-full">{{ form.processing ? '登録中…' : admin ? 'アカウントを作成' : 'アカウントを登録' }}</button>
  </form>
</template>
