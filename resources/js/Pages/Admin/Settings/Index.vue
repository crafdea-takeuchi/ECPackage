<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import SettingsCards from '../../../Components/Admin/SettingsCards.vue';
defineProps({ cards: Array });
</script>

<template>
  <AdminLayout>
    <Head title="各種設定" />
    <h1 class="mb-7 text-3xl font-bold">各種設定</h1>
    <SettingsCards :cards="cards" />
  </AdminLayout>
</template>
