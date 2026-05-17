<template>
  <div class="space-y-2.5">
    <div v-if="line.type === 'income'" class="space-y-3 rounded-lg border border-gray-700 bg-gray-900/40 p-3">
      <div>
        <p class="text-sm font-medium text-gray-300">Income from taking debt?</p>
        <p class="mt-0.5 text-xs text-gray-500">Optional — attach to an existing loan or record new debt</p>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <button
          type="button"
          class="min-h-[40px] rounded-lg px-2 py-2 text-xs font-medium transition-colors"
          :class="line.income_debt_mode === 'none' ? 'bg-gray-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
          :disabled="disabled"
          @click="setIncomeDebtMode('none')"
        >
          No
        </button>
        <button
          type="button"
          class="min-h-[40px] rounded-lg px-2 py-2 text-xs font-medium transition-colors"
          :class="line.income_debt_mode === 'existing' ? 'bg-sky-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
          :disabled="disabled"
          @click="setIncomeDebtMode('existing')"
        >
          Existing
        </button>
        <button
          type="button"
          class="min-h-[40px] rounded-lg px-2 py-2 text-xs font-medium transition-colors"
          :class="line.income_debt_mode === 'new' ? 'bg-emerald-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
          :disabled="disabled"
          @click="setIncomeDebtMode('new')"
        >
          New
        </button>
      </div>
      <div v-if="line.income_debt_mode === 'existing'" class="space-y-1">
        <label class="block text-xs font-medium text-gray-400">Attach to debt</label>
        <select
          v-model.number="line.income_existing_debt_id"
          class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-sky-500 focus:outline-none disabled:opacity-50"
          :disabled="disabled"
        >
          <option :value="null" disabled>Select a debt</option>
          <option v-for="d in incomeAttachableDebts" :key="d.id" :value="d.id">
            {{ incomeDebtSelectLabel(d) }} — {{ formatCurrency(Number(d.balance) || 0) }}
          </option>
        </select>
      </div>
      <div v-if="line.income_debt_mode === 'new'" class="space-y-3">
        <div class="grid grid-cols-2 gap-2">
          <button
            type="button"
            class="min-h-[40px] rounded-lg px-3 py-2 text-xs font-medium transition-colors"
            :class="!line.income_new_is_interfamily ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
            :disabled="disabled"
            @click="line.income_new_is_interfamily = false"
          >
            External
          </button>
          <button
            type="button"
            class="min-h-[40px] rounded-lg px-3 py-2 text-xs font-medium transition-colors"
            :class="line.income_new_is_interfamily ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
            :disabled="disabled"
            @click="line.income_new_is_interfamily = true"
          >
            Family
          </button>
        </div>
        <div v-if="line.income_new_is_interfamily">
          <label class="mb-1 block text-xs font-medium text-gray-400">Family creditor</label>
          <select
            v-model.number="line.income_new_creditor_id"
            class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none disabled:opacity-50"
            :disabled="disabled"
          >
            <option :value="null" disabled>Select member</option>
            <option v-for="member in familyUsers" :key="member.id" :value="member.id">
              {{ member.name }}
            </option>
          </select>
        </div>
        <div v-else>
          <label class="mb-1 block text-xs font-medium text-gray-400">Creditor name</label>
          <input
            v-model="line.income_new_creditor_name"
            type="text"
            placeholder="e.g., Bank"
            class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none disabled:opacity-50"
            :disabled="disabled"
          />
        </div>
        <label class="flex items-center gap-2 text-xs text-gray-300">
          <input
            v-model="line.income_new_is_family_debt"
            type="checkbox"
            class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600"
            :disabled="disabled"
          />
          Visible to all family members
        </label>
      </div>

      <PlaidImportRepaymentOptions
        :model="line"
        :amount="line.amount"
        amount-label="line"
        :disabled="disabled"
        :family-users="familyUsers"
      />
    </div>

    <div v-if="line.type === 'expense'" class="space-y-2">
      <div
        class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-900/40 p-3 transition-colors hover:border-gray-600"
        role="button"
        tabindex="0"
        @click="!disabled && togglePayTowardDebt()"
        @keydown.enter.prevent="!disabled && togglePayTowardDebt()"
        @keydown.space.prevent="!disabled && togglePayTowardDebt()"
      >
        <div>
          <p class="text-sm font-medium text-gray-300">Pay toward a tracked debt</p>
          <p class="mt-0.5 text-xs text-gray-500">Links this expense to a debt</p>
        </div>
        <div
          class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
          :class="line.pay_toward_debt ? 'bg-sky-600' : 'bg-gray-700'"
        >
          <div
            class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
            :class="line.pay_toward_debt ? 'translate-x-5' : 'translate-x-1'"
          />
        </div>
      </div>
      <div v-if="line.pay_toward_debt" class="space-y-1">
        <label class="block text-xs font-medium text-gray-400">Which debt?</label>
        <select
          v-model.number="line.debt_id"
          class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-sky-500 focus:outline-none disabled:opacity-50"
          :disabled="disabled"
        >
          <option :value="null" disabled>Select a debt</option>
          <option v-for="d in payableDebts" :key="d.id" :value="d.id">
            {{ debtSelectLabel(d) }} — {{ formatCurrency(Number(d.balance) || 0) }} left
          </option>
        </select>
      </div>
    </div>

    <div
      v-if="line.type === 'expense'"
      class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-900/40 p-3 transition-colors hover:border-gray-600"
      role="button"
      tabindex="0"
      @click="!disabled && toggleFamilySplit()"
      @keydown.enter.prevent="!disabled && toggleFamilySplit()"
      @keydown.space.prevent="!disabled && toggleFamilySplit()"
    >
      <div>
        <p class="text-sm font-medium text-gray-300">Split between family members</p>
        <p class="mt-0.5 text-xs text-gray-500">Divide this expense</p>
      </div>
      <div
        class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
        :class="line.is_split ? 'bg-blue-600' : 'bg-gray-700'"
      >
        <div
          class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
          :class="line.is_split ? 'translate-x-5' : 'translate-x-1'"
        />
      </div>
    </div>

    <div v-if="line.type === 'expense' && line.is_split">
      <SplitEditor
        :family-users="familyUsers"
        :total-amount="splitEditorAmount"
        :initial-splits="line.split_data"
        @update:splits="line.split_data = $event"
      />
    </div>

    <div v-if="line.type === 'expense' && !line.pay_toward_debt" class="space-y-2">
      <div
        class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-900/40 p-3 transition-colors hover:border-gray-600"
        role="button"
        tabindex="0"
        @click="!disabled && toggleAdvanceFund()"
        @keydown.enter.prevent="!disabled && toggleAdvanceFund()"
        @keydown.space.prevent="!disabled && toggleAdvanceFund()"
      >
        <div>
          <p class="text-sm font-medium text-gray-300">Advance against fund</p>
          <p class="mt-0.5 text-xs text-gray-500">Deduct from a fund at month close</p>
        </div>
        <div
          class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
          :class="line.advance_fund_id !== null ? 'bg-amber-600' : 'bg-gray-700'"
        >
          <div
            class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
            :class="line.advance_fund_id !== null ? 'translate-x-5' : 'translate-x-1'"
          />
        </div>
      </div>
      <select
        v-if="line.advance_fund_id !== null"
        v-model.number="line.advance_fund_id"
        class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-amber-500 focus:outline-none disabled:opacity-50"
        :disabled="disabled"
        @change="onAdvanceFundChange()"
      >
        <option :value="null" disabled>Select a fund</option>
        <option v-for="fund in funds" :key="fund.id" :value="fund.id">
          {{ fund.name }}{{ fund.scope === 'family' ? ' (family)' : '' }}
        </option>
      </select>
      <div
        v-if="line.advance_fund_id !== null && fundHasNonNecessityRule"
        class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-900/40 p-3 transition-colors hover:border-gray-600"
        role="button"
        tabindex="0"
        @click="!disabled && (line.is_non_necessity = !line.is_non_necessity)"
        @keydown.enter.prevent="!disabled && (line.is_non_necessity = !line.is_non_necessity)"
        @keydown.space.prevent="!disabled && (line.is_non_necessity = !line.is_non_necessity)"
      >
        <div>
          <p class="text-sm font-medium text-gray-300">Mark as non-necessity</p>
          <p class="mt-0.5 text-xs text-gray-500">Excluded from expense basis when the fund allows it</p>
        </div>
        <div
          class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
          :class="line.is_non_necessity ? 'bg-violet-600' : 'bg-gray-700'"
        >
          <div
            class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
            :class="line.is_non_necessity ? 'translate-x-5' : 'translate-x-1'"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import PlaidImportRepaymentOptions from './PlaidImportRepaymentOptions.vue';
import SplitEditor from './SplitEditor.vue';
import {
  equalSplitPayloadForFamilyUsers,
  hasPositiveSplitShares,
} from '../support/equalFamilySplit.js';

const props = defineProps({
  line: {
    type: Object,
    required: true,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  categories: {
    type: Array,
    default: () => [],
  },
  funds: {
    type: Array,
    default: () => [],
  },
  familyUsers: {
    type: Array,
    default: () => [],
  },
  payableDebts: {
    type: Array,
    default: () => [],
  },
  incomeAttachableDebts: {
    type: Array,
    default: () => [],
  },
});


const splitEditorAmount = computed(() => {
  const parsed = parseFloat(props.line.amount);

  return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
});

const fundHasNonNecessityRule = computed(() => {
  if (props.line.advance_fund_id === null || props.line.advance_fund_id === undefined) {
    return false;
  }
  const fund = props.funds.find((x) => Number(x.id) === Number(props.line.advance_fund_id));

  return fund?.has_non_necessity_rule === true;
});

function formatCurrency(amount) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(
    Number(amount) || 0,
  );
}

function debtSelectLabel(d) {
  if (d.creditor?.name) {
    return `To ${d.creditor.name}`;
  }
  if (d.creditor_name) {
    return `To ${d.creditor_name}`;
  }
  if (d.fund?.name) {
    return `Borrowed: ${d.fund.name}`;
  }
  if (d.description) {
    return d.description;
  }

  return `Debt #${d.id}`;
}

function incomeDebtSelectLabel(d) {
  if (d.creditor?.name) {
    return `Owed to ${d.creditor.name}`;
  }
  if (d.creditor_name) {
    return `Owed to ${d.creditor_name}`;
  }
  if (d.description) {
    return d.description;
  }

  return `Debt #${d.id}`;
}

function setIncomeDebtMode(mode) {
  props.line.income_debt_mode = mode;
  if (mode === 'existing') {
    props.line.income_new_is_family_debt = false;
    props.line.income_new_is_interfamily = false;
    props.line.income_new_creditor_id = null;
    props.line.income_new_creditor_name = '';
    props.line.income_new_description = '';
    props.line.income_new_interest_enabled = false;
    props.line.income_new_interest_rate = 0;
    if (props.incomeAttachableDebts.length === 1) {
      props.line.income_existing_debt_id = props.incomeAttachableDebts[0].id;
    } else {
      props.line.income_existing_debt_id = null;
    }

    return;
  }
  props.line.income_existing_debt_id = null;
  if (mode !== 'new') {
    props.line.income_debt_mode = 'none';
    props.line.income_new_is_family_debt = false;
    props.line.income_new_is_interfamily = false;
    props.line.income_new_creditor_id = null;
    props.line.income_new_creditor_name = '';
    props.line.income_new_description = '';
    props.line.income_new_interest_enabled = false;
    props.line.income_new_interest_rate = 0;
  }
}

function togglePayTowardDebt() {
  props.line.pay_toward_debt = !props.line.pay_toward_debt;
  if (props.line.pay_toward_debt) {
    props.line.advance_fund_id = null;
    props.line.is_non_necessity = false;
    const pd = props.payableDebts;
    if (pd.length === 1) {
      props.line.debt_id = pd[0].id;
    } else if (!pd.some((d) => Number(d.id) === Number(props.line.debt_id))) {
      props.line.debt_id = null;
    }
  } else {
    props.line.debt_id = null;
  }
}

function toggleFamilySplit() {
  props.line.is_split = !props.line.is_split;
  if (!props.line.is_split) {
    props.line.split_data = [];
    props.line.is_non_necessity = false;

    return;
  }
  if (!props.familyUsers.length || !hasPositiveSplitShares(props.line.split_data)) {
    props.line.split_data = equalSplitPayloadForFamilyUsers(props.familyUsers);
  }
}

function toggleAdvanceFund() {
  if (props.line.advance_fund_id !== null) {
    props.line.advance_fund_id = null;
    props.line.is_non_necessity = false;
  } else {
    props.line.advance_fund_id = props.funds.length > 0 ? props.funds[0].id : null;
  }
}

function onAdvanceFundChange() {
  if (props.line.advance_fund_id === null) {
    props.line.is_non_necessity = false;
  } else if (!fundHasNonNecessityRule.value) {
    props.line.is_non_necessity = false;
  }
}
</script>
