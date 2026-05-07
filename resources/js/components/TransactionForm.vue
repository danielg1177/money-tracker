<template>
  <form @submit.prevent="handleSubmit" class="min-w-0 max-w-full space-y-6 pb-4">
    <div
      v-if="isDebtPaymentIncomeEditBlocked"
      class="rounded-lg border border-amber-700/50 bg-amber-900/20 p-3 text-sm text-amber-200"
    >
      Debt repayment income entries cannot be edited directly. Edit the matching expense payment instead.
    </div>

    <!-- Type Toggle (Income / Expense) -->
    <div>
      <label class="block text-sm font-medium text-gray-300 mb-3">Type</label>
      <div class="grid grid-cols-2 gap-2">
        <button
          type="button"
          :disabled="submitLoading || isDebtPaymentIncomeEditBlocked"
          @click="form.type = 'expense'"
          :class="[
            'py-2 px-4 rounded-lg font-medium transition-colors disabled:opacity-50',
            form.type === 'expense'
              ? 'bg-red-600 text-white'
              : 'bg-gray-800 text-gray-400 hover:bg-gray-700'
          ]"
        >
          Expense
        </button>
        <button
          type="button"
          :disabled="submitLoading || isDebtPaymentIncomeEditBlocked"
          @click="form.type = 'income'"
          :class="[
            'py-2 px-4 rounded-lg font-medium transition-colors disabled:opacity-50',
            form.type === 'income'
              ? 'bg-green-600 text-white'
              : 'bg-gray-800 text-gray-400 hover:bg-gray-700'
          ]"
        >
          Income
        </button>
      </div>
    </div>

    <!-- Amount -->
    <div>
      <label for="amount" class="block text-sm font-medium text-gray-300 mb-2">
        Amount
      </label>
      <div class="relative">
        <span class="absolute left-4 top-2 text-gray-400">$</span>
        <input
          id="amount"
          v-model.number="form.amount"
          v-bind="mobileDecimalNumberAttrs"
          type="number"
          step="0.01"
          min="0"
          required
          :disabled="submitLoading || isDebtPaymentIncomeEditBlocked"
          class="w-full pl-8 pr-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors disabled:opacity-50"
          placeholder="0.00"
        />
      </div>
    </div>

    <!-- Category Select (filtered by type) -->
    <div>
      <label for="category" class="block text-sm font-medium text-gray-300 mb-2">
        Category
      </label>
      <select
        id="category"
        v-model.number="form.category_id"
        required
        :disabled="submitLoading || isDebtPaymentIncomeEditBlocked"
        class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors disabled:opacity-50"
      >
        <option value="" disabled selected>Select a category</option>
        <option
          v-for="cat in filteredCategories"
          :key="cat.id"
          :value="cat.id"
        >
          {{ cat.icon }} {{ cat.name }}
        </option>
      </select>
    </div>

    <!-- Description -->
    <div>
      <label for="description" class="block text-sm font-medium text-gray-300 mb-2">
        Description <span class="text-gray-500">(optional)</span>
      </label>
      <input
        id="description"
        v-model="form.description"
        type="text"
        :disabled="submitLoading || isDebtPaymentIncomeEditBlocked"
        class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors disabled:opacity-50"
        placeholder="Add a note..."
      />
    </div>

    <!-- Date: clip overflow — WebKit date shadow UI can exceed box width on iPhone Safari -->
    <div class="min-w-0 max-w-full">
      <label for="date" class="block text-sm font-medium text-gray-300 mb-2">
        Date
      </label>
      <div
        class="min-w-0 max-w-full overflow-hidden rounded-lg border border-gray-700 bg-gray-800 [contain:layout]"
      >
        <input
          id="date"
          v-model="form.transaction_date"
          type="date"
          required
          :disabled="submitLoading || isDebtPaymentIncomeEditBlocked"
          class="native-date-input w-full min-w-0 max-w-full border-0 bg-transparent px-3 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500/60 disabled:opacity-50"
        />
      </div>
    </div>

    <!-- Income debt association -->
    <div v-if="form.type === 'income'" class="space-y-3 rounded-lg border border-gray-700 bg-gray-800/50 p-3">
      <div>
        <p class="text-sm font-medium text-gray-300">Is this income from taking debt?</p>
        <p class="mt-0.5 text-xs text-gray-500">Keep as regular income while optionally creating or adding debt</p>
      </div>

      <div class="grid grid-cols-3 gap-2">
        <button
          type="button"
          @click="form.income_debt_mode = 'none'"
          :class="[
            'rounded-lg px-2 py-2 text-xs font-medium transition-colors',
            form.income_debt_mode === 'none' ? 'bg-gray-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
          ]"
        >
          No
        </button>
        <button
          type="button"
          @click="form.income_debt_mode = 'existing'"
          :class="[
            'rounded-lg px-2 py-2 text-xs font-medium transition-colors',
            form.income_debt_mode === 'existing' ? 'bg-sky-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
          ]"
        >
          Existing
        </button>
        <button
          type="button"
          @click="form.income_debt_mode = 'new'"
          :class="[
            'rounded-lg px-2 py-2 text-xs font-medium transition-colors',
            form.income_debt_mode === 'new' ? 'bg-emerald-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
          ]"
        >
          New Debt
        </button>
      </div>

      <div v-if="form.income_debt_mode === 'existing'" class="space-y-1">
        <label for="income-existing-debt" class="block text-xs font-medium text-gray-400">Attach to debt</label>
        <select
          id="income-existing-debt"
          v-model.number="form.income_existing_debt_id"
          :disabled="submitLoading || isDebtPaymentIncomeEditBlocked"
          class="w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-white focus:border-sky-500 focus:outline-none"
        >
          <option :value="null" disabled>Select a debt</option>
          <option v-for="d in incomeAttachableDebts" :key="d.id" :value="d.id">
            {{ incomeDebtSelectLabel(d) }} — now {{ formatCurrency(Number(d.balance) || 0) }}
          </option>
        </select>
      </div>

      <div v-if="form.income_debt_mode === 'new'" class="space-y-3">
        <div class="grid grid-cols-2 gap-2">
          <button
            type="button"
            @click="form.income_new_is_interfamily = false"
            :class="[
              'rounded-lg px-3 py-2 text-xs font-medium transition-colors',
              form.income_new_is_interfamily ? 'bg-gray-700 text-gray-300' : 'bg-blue-600 text-white'
            ]"
          >
            External
          </button>
          <button
            type="button"
            @click="form.income_new_is_interfamily = true"
            :class="[
              'rounded-lg px-3 py-2 text-xs font-medium transition-colors',
              form.income_new_is_interfamily ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-300'
            ]"
          >
            Family Member
          </button>
        </div>

        <div v-if="form.income_new_is_interfamily">
          <label class="mb-1 block text-xs font-medium text-gray-400">Family creditor</label>
          <select
            v-model.number="form.income_new_creditor_id"
            class="w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-white focus:border-blue-500 focus:outline-none"
          >
            <option :value="null" disabled>Select a family member</option>
            <option v-for="member in familyUsers" :key="member.id" :value="member.id">
              {{ member.name }}
            </option>
          </select>
        </div>

        <div v-else>
          <label class="mb-1 block text-xs font-medium text-gray-400">Creditor name</label>
          <input
            v-model="form.income_new_creditor_name"
            type="text"
            placeholder="e.g., Bank of America"
            class="w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
          />
        </div>

        <label class="flex items-center gap-2 text-xs text-gray-300">
          <input v-model="form.income_new_is_family_debt" type="checkbox" class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600" />
          Visible to all family members
        </label>

        <div>
          <label class="mb-1 block text-xs font-medium text-gray-400">Debt description (optional)</label>
          <input
            v-model="form.income_new_description"
            type="text"
            placeholder="Defaults to transaction description"
            class="w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none"
          />
        </div>

        <div class="space-y-2 rounded-lg border border-gray-700 bg-gray-900/30 p-3">
          <div class="flex items-center justify-between">
            <label class="text-xs font-medium text-gray-300">Apply monthly interest at closeout</label>
            <button
              type="button"
              @click="form.income_new_interest_enabled = !form.income_new_interest_enabled"
              :class="[
                'relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200',
                form.income_new_interest_enabled ? 'bg-amber-600' : 'bg-gray-600'
              ]"
            >
              <span
                :class="[
                  'pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200',
                  form.income_new_interest_enabled ? 'translate-x-4' : 'translate-x-0'
                ]"
              />
            </button>
          </div>

          <div v-if="form.income_new_interest_enabled">
            <label class="mb-1 block text-xs font-medium text-gray-400">Annual Interest Rate (APR %)</label>
            <input
              v-model.number="form.income_new_interest_rate"
              v-bind="mobileDecimalNumberAttrs"
              type="number"
              min="0"
              max="100"
              step="0.01"
              class="w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-white placeholder-gray-500 focus:border-amber-500 focus:outline-none"
              placeholder="e.g. 12.50"
            />
          </div>
        </div>

      </div>
    </div>

    <!-- Pay toward debt (expense only) -->
    <div v-if="form.type === 'expense'" class="space-y-2">
      <div
        @click="!isDebtPaymentIncomeEditBlocked && togglePayTowardDebt()"
        class="flex items-center justify-between p-3 bg-gray-800 border border-gray-700 rounded-lg transition-colors hover:border-gray-600"
        :class="isDebtPaymentIncomeEditBlocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'"
      >
        <div>
          <p class="text-sm font-medium text-gray-300">Pay toward a tracked debt</p>
          <p class="text-xs text-gray-500 mt-0.5">Links this expense to a debt and credits the other person</p>
        </div>
        <div
          class="w-10 h-6 rounded-full transition-colors relative flex-shrink-0"
          :class="payTowardDebt ? 'bg-sky-600' : 'bg-gray-700'"
        >
          <div
            class="absolute top-1 w-4 h-4 bg-white rounded-full shadow transition-transform"
            :class="payTowardDebt ? 'translate-x-5' : 'translate-x-1'"
          />
        </div>
      </div>
      <div v-if="payTowardDebt" class="space-y-1">
        <label for="debt-select" class="block text-xs font-medium text-gray-400">Which debt?</label>
        <select
          id="debt-select"
          v-model.number="form.debt_id"
          :disabled="submitLoading || isDebtPaymentIncomeEditBlocked"
          class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-sky-500"
        >
          <option :value="null" disabled>Select a debt</option>
          <option v-for="d in payableDebts" :key="d.id" :value="d.id">
            {{ debtSelectLabel(d) }} — {{ formatCurrency(Number(d.balance) || 0) }} left
          </option>
        </select>
        <p v-if="payableDebts.length === 0" class="text-xs text-amber-400">
          No payable debts found. Open Debts to add one, or refresh the app.
        </p>
      </div>
    </div>

    <!-- Split Toggle (expense only) -->
    <div
      v-if="form.type === 'expense'"
      @click="!isDebtPaymentIncomeEditBlocked && (form.is_split = !form.is_split)"
      class="flex items-center justify-between p-3 bg-gray-800 border border-gray-700 rounded-lg transition-colors hover:border-gray-600"
      :class="isDebtPaymentIncomeEditBlocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'"
    >
      <div>
        <p class="text-sm font-medium text-gray-300">Split between family members</p>
        <p class="text-xs text-gray-500 mt-0.5">Divide this expense among family members</p>
      </div>
      <div
        class="w-10 h-6 rounded-full transition-colors relative flex-shrink-0"
        :class="form.is_split ? 'bg-blue-600' : 'bg-gray-700'"
      >
        <div
          class="absolute top-1 w-4 h-4 bg-white rounded-full shadow transition-transform"
          :class="form.is_split ? 'translate-x-5' : 'translate-x-1'"
        />
      </div>
    </div>

    <!-- Advance Against Fund -->
    <div v-if="form.type === 'expense' && !payTowardDebt" class="space-y-2">
      <div
        @click="!isDebtPaymentIncomeEditBlocked && toggleAdvanceFund()"
        class="flex items-center justify-between p-3 bg-gray-800 border border-gray-700 rounded-lg transition-colors hover:border-gray-600"
        :class="isDebtPaymentIncomeEditBlocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'"
      >
        <div>
          <p class="text-sm font-medium text-gray-300">Advance against fund</p>
          <p class="text-xs text-gray-500 mt-0.5">Deduct from a fund's allocation at month close</p>
        </div>
        <div
          class="w-10 h-6 rounded-full transition-colors relative flex-shrink-0"
          :class="form.advance_fund_id !== null ? 'bg-amber-600' : 'bg-gray-700'"
        >
          <div
            class="absolute top-1 w-4 h-4 bg-white rounded-full shadow transition-transform"
            :class="form.advance_fund_id !== null ? 'translate-x-5' : 'translate-x-1'"
          />
        </div>
      </div>
      <select
        v-if="form.advance_fund_id !== null"
        v-model.number="form.advance_fund_id"
        class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-amber-500"
      >
        <option :value="null" disabled>Select a fund</option>
        <option v-for="fund in funds" :key="fund.id" :value="fund.id">
          {{ fund.name }} ({{ fund.scope === 'family' || fund.family_id ? 'Family' : 'Personal' }})
        </option>
      </select>
    </div>

    <!-- Split Editor -->
    <div v-if="form.type === 'expense' && form.is_split">
      <SplitEditor
        :family-users="familyUsers"
        :total-amount="form.amount"
        :initial-splits="form.split_data"
        @update:splits="form.split_data = $event"
      />
    </div>

    <!-- Error -->
    <div v-if="formError" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ formError }}</p>
    </div>

    <!-- Submit Button -->
    <div class="flex gap-2 pt-4">
      <button
        type="button"
        @click="handleClose"
        :disabled="submitLoading"
        class="flex-1 py-2 px-4 bg-gray-800 hover:bg-gray-700 text-gray-300 font-medium rounded-lg transition-colors disabled:opacity-50"
      >
        Cancel
      </button>
      <button
        type="submit"
        :disabled="submitLoading || isDebtPaymentIncomeEditBlocked"
        class="flex-1 py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors disabled:bg-gray-700 disabled:cursor-not-allowed flex items-center justify-center gap-2"
      >
        <span v-if="submitLoading">
          <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          Saving...
        </span>
        <span v-else>{{ isEditMode ? 'Save Changes' : 'Create Transaction' }}</span>
      </button>
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useApi } from '../composables/useApi';
import { mobileDecimalNumberAttrs } from '../support/mobileNumericInputAttrs.js';
import SplitEditor from './SplitEditor.vue';
import {
  equalSplitPayloadForFamilyUsers,
  hasPositiveSplitShares,
} from '../support/equalFamilySplit.js';

const props = defineProps({
  categories: {
    type: Array,
    required: true,
  },
  familyUsers: {
    type: Array,
    required: true,
  },
  funds: {
    type: Array,
    default: () => [],
  },
  debtsPayload: {
    type: Object,
    default: () => ({
      owed: [],
      owing: [],
      family_debts: [],
    }),
  },
  transaction: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['created', 'updated', 'close']);

const { post, put, loading: submitLoading } = useApi();
const formError = ref(null);
const payTowardDebt = ref(false);

const form = ref({
  type: 'expense',
  amount: null,
  category_id: null,
  description: '',
  transaction_date: new Date().toISOString().split('T')[0],
  is_split: false,
  split_data: [],
  advance_fund_id: null,
  debt_id: null,
  income_debt_mode: 'none',
  income_existing_debt_id: null,
  income_new_is_family_debt: false,
  income_new_is_interfamily: false,
  income_new_creditor_id: null,
  income_new_creditor_name: '',
  income_new_description: '',
  income_new_interest_enabled: false,
  income_new_interest_rate: 0,
});

const payableDebts = computed(() => {
  const list = [
    ...(props.debtsPayload?.owed || []),
    ...(props.debtsPayload?.family_debts || []),
  ];
  return list.filter((d) => !d.is_pending_closeout && Number(d.balance) > 0);
});

const incomeAttachableDebts = computed(() => {
  const list = props.debtsPayload?.owed || [];
  return list.filter((d) => !d.is_pending_closeout && Number(d.balance) >= 0);
});

const filteredCategories = computed(() => {
  return props.categories
    .filter((cat) => {
      if (form.value.type === 'income') {
        return cat.is_income;
      }

      return cat.is_expense;
    })
    .sort((a, b) =>
      String(a.name ?? '').localeCompare(String(b.name ?? ''), undefined, {
        sensitivity: 'base',
      })
    );
});

const selectedCategory = computed(() => {
  return filteredCategories.value.find(cat => cat.id === form.value.category_id);
});

const isEditMode = computed(() => !!props.transaction);

const isDebtPaymentIncomeEditBlocked = computed(
  () => isEditMode.value
    && Boolean(props.transaction?.is_debt_payment)
    && props.transaction?.type === 'income'
);

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
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

function togglePayTowardDebt() {
  payTowardDebt.value = !payTowardDebt.value;
}

function normalizeDateForInput(value) {
  if (!value) {
    return new Date().toISOString().split('T')[0];
  }

  const stringValue = String(value);
  return stringValue.includes('T') ? stringValue.split('T')[0] : stringValue;
}

function normalizeSplits(splits = []) {
  return splits.map((split) => ({
    user_id: split.user_id,
    share_percentage: split.share_percentage ?? split.percentage ?? 0,
  }));
}

watch(
  () => props.transaction,
  (newTransaction) => {
    if (newTransaction) {
      payTowardDebt.value = !!newTransaction.is_debt_payment;
      form.value = {
        type: newTransaction.type,
        amount: parseFloat(newTransaction.amount),
        category_id: newTransaction.category_id,
        description: newTransaction.description || '',
        transaction_date: normalizeDateForInput(newTransaction.transaction_date),
        is_split: newTransaction.is_split,
        split_data: normalizeSplits(newTransaction.split_data || []),
        advance_fund_id: newTransaction.advance_fund_id ?? null,
        debt_id: newTransaction.debt_id ?? null,
        income_debt_mode: newTransaction.type === 'income' && newTransaction.debt_id ? 'existing' : 'none',
        income_existing_debt_id: newTransaction.type === 'income' ? (newTransaction.debt_id ?? null) : null,
        income_new_is_family_debt: false,
        income_new_is_interfamily: false,
        income_new_creditor_id: null,
        income_new_creditor_name: '',
        income_new_description: '',
        income_new_interest_enabled: false,
        income_new_interest_rate: 0,
      };
    } else {
      resetForm();
    }
  },
  { immediate: true }
);

watch(payTowardDebt, (on) => {
  if (!on) {
    form.value.debt_id = null;

    return;
  }
  form.value.advance_fund_id = null;
  if (payableDebts.value.length === 1) {
    form.value.debt_id = payableDebts.value[0].id;
  }
});

watch(() => form.value.is_split, (newVal) => {
  if (!newVal) {
    form.value.split_data = [];

    return;
  }

  if (!props.familyUsers?.length || hasPositiveSplitShares(form.value.split_data)) {
    return;
  }

  form.value.split_data = equalSplitPayloadForFamilyUsers(props.familyUsers);
});

watch(() => form.value.type, (newType) => {
  if (newType === 'income') {
    form.value.advance_fund_id = null;
    form.value.is_split = false;
    form.value.split_data = [];
    payTowardDebt.value = false;
    form.value.debt_id = null;
    return;
  }

  form.value.income_debt_mode = 'none';
  form.value.income_existing_debt_id = null;
  form.value.income_new_is_family_debt = false;
  form.value.income_new_is_interfamily = false;
  form.value.income_new_creditor_id = null;
  form.value.income_new_creditor_name = '';
  form.value.income_new_description = '';
  form.value.income_new_interest_enabled = false;
  form.value.income_new_interest_rate = 0;
});

watch(() => form.value.income_debt_mode, (mode) => {
  if (mode === 'existing') {
    form.value.income_new_is_family_debt = false;
    form.value.income_new_is_interfamily = false;
    form.value.income_new_creditor_id = null;
    form.value.income_new_creditor_name = '';
    form.value.income_new_description = '';
    if (incomeAttachableDebts.value.length === 1) {
      form.value.income_existing_debt_id = incomeAttachableDebts.value[0].id;
    }
    return;
  }

  form.value.income_existing_debt_id = null;
  if (mode !== 'new') {
    form.value.income_new_is_family_debt = false;
    form.value.income_new_is_interfamily = false;
    form.value.income_new_creditor_id = null;
    form.value.income_new_creditor_name = '';
    form.value.income_new_description = '';
    form.value.income_new_interest_enabled = false;
    form.value.income_new_interest_rate = 0;
  }
});

watch(() => form.value.category_id, () => {
  if (form.value.type !== 'expense' || payTowardDebt.value) {
    return;
  }
  if (selectedCategory.value?.is_split_default && selectedCategory.value?.split_default?.length) {
    form.value.is_split = true;
    form.value.split_data = props.familyUsers?.length
      ? equalSplitPayloadForFamilyUsers(props.familyUsers)
      : [];
  }
  if (selectedCategory.value?.advance_fund_id) {
    form.value.advance_fund_id = selectedCategory.value.advance_fund_id;
  }
});

watch(
  () => props.familyUsers,
  () => {
    if (form.value.type !== 'expense' || !form.value.is_split || !props.familyUsers?.length) {
      return;
    }
    if (hasPositiveSplitShares(form.value.split_data)) {
      return;
    }
    form.value.split_data = equalSplitPayloadForFamilyUsers(props.familyUsers);
  },
  { deep: true }
);

function resetForm() {
  payTowardDebt.value = false;
  form.value = {
    type: 'expense',
    amount: null,
    category_id: null,
    description: '',
    transaction_date: new Date().toISOString().split('T')[0],
    is_split: false,
    split_data: [],
    advance_fund_id: null,
    debt_id: null,
    income_debt_mode: 'none',
    income_existing_debt_id: null,
    income_new_is_family_debt: false,
    income_new_is_interfamily: false,
    income_new_creditor_id: null,
    income_new_creditor_name: '',
    income_new_description: '',
    income_new_interest_enabled: false,
    income_new_interest_rate: 0,
  };
  formError.value = null;
}

function toggleAdvanceFund() {
  if (form.value.advance_fund_id !== null) {
    form.value.advance_fund_id = null;
  } else {
    form.value.advance_fund_id = props.funds.length > 0 ? props.funds[0].id : null;
  }
}

function handleClose() {
  resetForm();
  emit('close');
}

async function handleSubmit() {
  formError.value = null;

  if (isDebtPaymentIncomeEditBlocked.value) {
    formError.value = 'This transaction cannot be edited.';
    return;
  }

  if (!form.value.amount || form.value.amount <= 0) {
    formError.value = 'Please enter a valid amount';
    return;
  }

  if (!form.value.category_id) {
    formError.value = 'Please select a category';
    return;
  }

  if (form.value.type === 'expense' && payTowardDebt.value) {
    if (!form.value.debt_id) {
      formError.value = 'Select which debt you are paying toward';
      return;
    }
  }

  if (form.value.type === 'income') {
    if (form.value.income_debt_mode === 'existing' && !form.value.income_existing_debt_id) {
      formError.value = 'Select which existing debt this income belongs to';
      return;
    }

    if (form.value.income_debt_mode === 'new') {
      if (form.value.income_new_is_interfamily && !form.value.income_new_creditor_id) {
        formError.value = 'Select which family member is the creditor';
        return;
      }
      if (!form.value.income_new_is_interfamily && !form.value.income_new_creditor_name?.trim()) {
        formError.value = 'Enter the creditor name for the new debt';
        return;
      }
      if (form.value.income_new_interest_enabled) {
        const interestRate = Number(form.value.income_new_interest_rate);
        if (!Number.isFinite(interestRate) || interestRate < 0 || interestRate > 100) {
          formError.value = 'Interest rate must be between 0 and 100';
          return;
        }
      }
    }
  }

  try {
    const payload = {
      type: form.value.type,
      amount: form.value.amount,
      category_id: form.value.category_id,
      description: form.value.description,
      transaction_date: form.value.transaction_date,
      is_split: form.value.type === 'expense' && form.value.is_split,
      advance_fund_id:
        form.value.type === 'expense' && !payTowardDebt.value ? (form.value.advance_fund_id || null) : null,
      ...(form.value.type === 'expense' && form.value.is_split
        ? { split_data: form.value.split_data }
        : {}),
      ...(form.value.type === 'expense' && payTowardDebt.value && form.value.debt_id
        ? { debt_id: form.value.debt_id }
        : {}),
      ...(form.value.type === 'income'
        ? {
            income_debt_mode: form.value.income_debt_mode,
            income_existing_debt_id:
              form.value.income_debt_mode === 'existing' ? form.value.income_existing_debt_id : null,
            income_new_is_family_debt:
              form.value.income_debt_mode === 'new' ? Boolean(form.value.income_new_is_family_debt) : false,
            income_new_is_interfamily:
              form.value.income_debt_mode === 'new' ? Boolean(form.value.income_new_is_interfamily) : false,
            income_new_creditor_id:
              form.value.income_debt_mode === 'new' && form.value.income_new_is_interfamily
                ? form.value.income_new_creditor_id
                : null,
            income_new_creditor_name:
              form.value.income_debt_mode === 'new' && !form.value.income_new_is_interfamily
                ? form.value.income_new_creditor_name
                : null,
            income_new_description:
              form.value.income_debt_mode === 'new' && form.value.income_new_description?.trim()
                ? form.value.income_new_description
                : null,
            income_new_interest_enabled:
              form.value.income_debt_mode === 'new' ? Boolean(form.value.income_new_interest_enabled) : false,
            income_new_interest_rate:
              form.value.income_debt_mode === 'new' && form.value.income_new_interest_enabled
                ? form.value.income_new_interest_rate
                : null,
          }
        : {}),
    };

    if (isEditMode.value) {
      const transaction = await put(`/transactions/${props.transaction.id}`, payload);
      resetForm();
      emit('updated', transaction);
    } else {
      const transaction = await post('/transactions', payload);
      resetForm();
      emit('created', transaction);
    }
  } catch (err) {
    formError.value = err.response?.data?.message || `Failed to ${isEditMode.value ? 'update' : 'create'} transaction`;
  }
}
</script>
