<script setup>
import CsvImportIndex from '../../../../Components/Admin/CsvImports/IndexPage.vue';

defineProps({ imports: Object, context: Object });
</script>

<template>
  <CsvImportIndex :imports="imports" :context="context" />
</template>