import { computed, reactive, watch } from 'vue';

export function useInventoryLotFilters(getFilters, getWarningDays, navigate) {
  const defaults = () => ({ q: '', product_id: '', supplier_id: '', received_month: '', availability: '', expiration_type: '', expiration_status: '', expiration_from: '', expiration_to: '', expiration_days: getWarningDays(), expiring_only: false, sort: 'received_desc' });
  const search = reactive(defaults());
  watch(getFilters, (filters) => {
    Object.assign(search, defaults(), filters, { expiring_only: filters.expiring_only === true || String(filters.expiring_only) === '1' });
  }, { immediate: true, deep: true });
  const expiredOnly = computed(() => getFilters().expiration_status === 'expired');

  function submit() {
    const query = Object.fromEntries(Object.entries({ ...search, expiring_only: search.expiring_only ? 1 : 0 })
      .filter(([key, value]) => key !== 'page' && value !== '' && value !== null && value !== undefined));
    navigate('/admin/inventory-lots', query, { preserveState: true });
  }
  function toggleExpired() {
    search.expiration_status = expiredOnly.value ? '' : 'expired';
    submit();
  }
  function reset() {
    Object.assign(search, defaults());
    navigate('/admin/inventory-lots', {}, { preserveState: true });
  }

  return { search, expiredOnly, submit, toggleExpired, reset };
}
