<template>
  <div class="space-y-3 rounded-lg border border-gray-700 bg-gray-900/40 p-3">
    <div
      class="flex cursor-pointer items-center justify-between"
      :class="disabled ? 'cursor-not-allowed opacity-60' : ''"
      role="button"
      tabindex="0"
      @click="!disabled && (model.is_debt_repayment_received = !model.is_debt_repayment_received)"
      @keydown.enter.prevent="!disabled && (model.is_debt_repayment_received = !model.is_debt_repayment_received)"
      @keydown.space.prevent="!disabled && (model.is_debt_repayment_received = !model.is_debt_repayment_received)"
    >
      <div>
        <p class="text-sm font-medium text-gray-300">Family member repaying a loan to me</p>
        <p class="mt-0.5 text-xs text-gray-500">
          Records the same debt payment on their account as if they had entered “Pay toward a tracked debt” themselves.
        </p>
      </div>
      <div
        class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
        :class="model.is_debt_repayment_received ? 'bg-sky-600' : 'bg-gray-700'"
      >
        <div
          class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
          :class="model.is_debt_repayment_received ? 'translate-x-5' : 'translate-x-1'"
        />
      </div>
    </div>

    <div v-if="model.is_debt_repayment_received">
      <label class="block text-xs font-medium text-gray-400">Which loan?</label>
      <select
        v-model.number="model.debt_repayment_received_id"
        class="mt-1 min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-sky-500 focus:outline-none disabled:opacity-50"
        :disabled="disabled"
      >
        <option :value="null" disabled>Select a loan</option>
        <option v-for="debt in receivableDebts" :key="debt.id" :value="debt.id">
          {{ debtSelectLabel(debt) }} — {{ formatCurrency(Number(debt.balance) || 0) }} left
        </option>
      </select>
      <p v-if="receivableDebts.length === 0" class="mt-1 text-xs text-amber-400">
        No active loans owed to you. Open Debts to see balances.
      </p>
    </div>
  </div>
</template>

<script setup>
import { watch } from 'vue';

const props = defineProps({
  model: {
    type: Object,
    required: true,
  },
  receivableDebts: {
    type: Array,
    default: () => [],
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

function formatCurrency(amount) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(
    Number(amount) || 0,
  );
}

function debtSelectLabel(debt) {
  const name = debt.debtor?.name ?? debt.debtor_name ?? 'Family member';

  return debt.description ? `${name} — ${debt.description}` : name;
}

watch(
  () => props.model.is_debt_repayment_received,
  (enabled) => {
    if (enabled) {
      if ('income_debt_mode' in props.model) {
        props.model.income_debt_mode = 'none';
        props.model.income_existing_debt_id = null;
        props.model.income_new_is_family_debt = false;
        props.model.income_new_is_interfamily = false;
        props.model.income_new_creditor_id = null;
        props.model.income_new_creditor_name = '';
        props.model.income_new_description = '';
        props.model.income_new_interest_enabled = false;
        props.model.income_new_interest_rate = 0;
      }
      if ('is_repayment_mode' in props.model) {
        props.model.is_repayment_mode = false;
        props.model.repayment_for_user_id = null;
        props.model.repayment_links = [];
      }
      if (props.receivableDebts.length === 1) {
        props.model.debt_repayment_received_id = props.receivableDebts[0].id;
      }

      return;
    }

    props.model.debt_repayment_received_id = null;
  },
);
</script>
