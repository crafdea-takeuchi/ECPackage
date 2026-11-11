<script setup>
import CsvImportShow from '../../../../Components/Admin/CsvImports/ShowPage.vue';

defineProps({
  context: Object,
  import: Object,
  preview: Object,
  errors: Array,
  definition_available: Boolean,
  can_execute: Boolean,
});
</script>

<template>
  <CsvImportShow
    :context="context"
    :import="import"
    :preview="preview"
    :errors="errors"
    :definition_available="definition_available"
    :can_execute="can_execute"
  />
</template>