<script setup>
defineProps({ paymentMethod: String, bank: Object, holderKanaOnly: Boolean, showTransferNote: Boolean });
</script>

<template>
  <section v-if="paymentMethod === 'bank_transfer'" class="mt-6 min-w-0 break-words rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="font-semibold">お振込先</h2>
    <div v-if="bank" class="mt-4 space-y-2 text-sm">
      <p class="font-semibold">{{ bank.bank_name }}　{{ bank.branch_name }}</p>
      <p>{{ bank.account_type }}　<span class="tabular-nums">{{ bank.account_number }}</span></p>
      <p>口座名義：{{ holderKanaOnly ? bank.account_holder_kana : bank.account_holder }}</p>
      <p v-if="!holderKanaOnly">口座名義カナ：{{ bank.account_holder_kana }}</p>
      <p v-if="bank.instructions" class="mt-4 whitespace-pre-wrap leading-6">{{ bank.instructions }}</p>
      <div v-if="showTransferNote" class="mt-4 rounded-lg bg-amber-50 p-4 leading-6 text-amber-900">
        <p>お振込みの際は、振込依頼人名の後ろに注文番号を付けてお振込みください。</p>
        <p>記入例：ヤマダタロウ ON2611AB23</p>
      </div>
    </div>
    <p v-else class="mt-4 text-sm text-slate-600">この注文には注文時点の振込先情報が保存されていません。</p>
  </section>
</template>
