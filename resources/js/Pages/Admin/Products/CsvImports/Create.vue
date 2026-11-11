<script setup>
import CsvImportCreate from '../../../../Components/Admin/CsvImports/CreatePage.vue';

defineProps({ context: Object, limits: Object });
</script>

<template>
  <CsvImportCreate :context="context" :limits="limits" />
</template>