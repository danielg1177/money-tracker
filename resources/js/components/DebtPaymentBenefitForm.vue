<template>
  <Teleport to="body">
    <div
      v-if="income"
      class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center p-0 sm:p-4"
    >
      <div class="absolute inset-0 bg-black/60" @click="emit('close')" />
      <div
        class="relative flex max-h-[92vh] w-full max-w-md flex-col overflow-hidden rounded-t-2xl border border-gray-700 bg-gray-900 shadow-xl sm:rounded-2xl"
        role="dialog"
        aria-modal="true"
      >
        <div class="flex shrink-0 items-center justify-between border-b border-gray-800 px-4 py-3">
          <div class="min-w-0">
            <h2 class="text-lg font-semibold text-white">
              {{ existingBenefit ? 'Edit recorded expense' : 'Record as expense' }}
            </h2>
            <p class="text-xs text-gray-400 mt-0.5">
              {{ formatCurrency(Number(income.amount) || 0) }} · {{ income.transaction_date }}
            </p>
          </div>
          <button
            type="button"
            class="rounded p-1 text-gray-400 hover:bg-gray-800 hover:text-white"
            @click="emit('close')"
          >
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form class="min-h-0 overflow-y-auto p-4 space-y-3" @submit.prevent="submit">
          <p class="text-sm text-gray-400">
            Keep the repayment income and also record what this covered for you (for example rent).
          </p>

          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1.5">Category</label>
            <select
              v-model.number="form.category_id"
              class="w-full px-3 py-2.5 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm focus:outline-none focus:border-blue-500"
              required
            >
              <option :value="null" disabled>Select a category</option>
              <option v-for="cat in expenseCategories" :key="cat.id" :value="cat.id">
                {{ cat.icon ? `${cat.icon} ` : '' }}{{ cat.name }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1.5">Description (optional)</label>
            <input
              v-model="form.description"
              type="text"
              class="w-full px-3 py-2.5 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm focus:outline-none focus:border-blue-500"
              placeholder="e.g. Rent covered by repayment"
            />
          </div>

          <div
            class="flex items-center justify-between p-3 bg-gray-800 border border-gray-700 rounded-lg cursor-pointer"
            @click="form.is_split = !form.is_split"
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

          <div
            class="flex items-center justify-between p-3 bg-gray-800 border border-gray-700 rounded-lg cursor-pointer"
            @click="form.is_necessity = !form.is_necessity"
          >
            <div>
              <p class="text-sm font-medium text-gray-300">{{ form.is_necessity ? 'Necessity' : 'Not a necessity' }}</p>
              <p class="text-xs text-gray-500 mt-0.5">Family pooled charity uses income minus necessities</p>
            </div>
            <div
              class="w-10 h-6 rounded-full transition-colors relative flex-shrink-0"
              :class="form.is_necessity ? 'bg-blue-600' : 'bg-violet-600'"
            >
              <div
                class="absolute top-1 w-4 h-4 bg-white rounded-full shadow transition-transform"
                :class="form.is_necessity ? 'translate-x-5' : 'translate-x-1'"
              />
            </div>
          </div>

          <div v-if="form.is_split">
            <SplitEditor
              :family-users="familyUsers"
              :total-amount="Number(income.amount) || 0"
              :initial-splits="form.split_data"
              @update:splits="form.split_data = $event"
            />
          </div>

          <div v-if="!form.is_split" class="space-y-2">
            <div
              class="flex items-center justify-between p-3 bg-gray-800 border border-gray-700 rounded-lg cursor-pointer"
              @click="toggleAdvanceFund"
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
              class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm focus:outline-none focus:border-amber-500"
            >
              <option :value="null" disabled>Select a fund</option>
              <option v-for="fund in funds" :key="fund.id" :value="fund.id">
                {{ fund.name }} ({{ fund.scope === 'family' || fund.family_id ? 'Family' : 'Personal' }})
              </option>
            </select>
            <div
              v-if="form.advance_fund_id !== null && selectedFundHasRemainingPercentageRule && allowExpenseBasisExclusion"
              class="flex items-center justify-between p-3 bg-gray-800 border border-gray-700 rounded-lg cursor-pointer"
              @click="form.exclude_from_expense_basis = !form.exclude_from_expense_basis"
            >
              <div>
                <p class="text-sm font-medium text-gray-300">Exclude from remaining</p>
                <p class="text-xs text-gray-500 mt-0.5">Do not subtract this from remaining closeout expenses</p>
              </div>
              <div
                class="w-10 h-6 rounded-full transition-colors relative flex-shrink-0"
                :class="form.exclude_from_expense_basis ? 'bg-violet-600' : 'bg-gray-700'"
              >
                <div
                  class="absolute top-1 w-4 h-4 bg-white rounded-full shadow transition-transform"
                  :class="form.exclude_from_expense_basis ? 'translate-x-5' : 'translate-x-1'"
                />
              </div>
            </div>
          </div>

          <div v-if="formError" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
            <p class="text-red-400 text-sm">{{ formError }}</p>
          </div>

          <div class="flex flex-col gap-2 pt-1 pb-safe">
            <button
              type="submit"
              class="w-full rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 disabled:opacity-60"
              :disabled="saving"
            >
              {{ saving ? 'Saving…' : (existingBenefit ? 'Update expense' : 'Save expense') }}
            </button>
            <button
              v-if="existingBenefit"
              type="button"
              class="w-full rounded-lg border border-red-700/50 bg-red-950/30 hover:bg-red-900/40 text-red-300 font-medium py-3 disabled:opacity-60"
              :disabled="saving"
              @click="removeBenefit"
            >
              {{ saving ? 'Working…' : 'Remove expense' }}
            </button>
            <button
              type="button"
              class="w-full rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-200 font-medium py-3"
              :disabled="saving"
              @click="emit('close')"
            >
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import SplitEditor from './SplitEditor.vue';
import { useApi } from '../composables/useApi';
import { useAuth } from '../composables/useAuth';
import { equalSplitPayloadForFamilyUsers } from '../support/equalFamilySplit.js';
import { allowsExpenseBasisExclusion } from '../support/closeoutMode.js';

const props = defineProps({
  income: {
    type: Object,
    default: null,
  },
  categories: {
    type: Array,
    default: () => [],
  },
  familyUsers: {
    type: Array,
    default: () => [],
  },
  funds: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close', 'saved', 'removed']);

const { post, put, del } = useApi();
const { user } = useAuth();

const form = ref({
  category_id: null,
  description: '',
  is_split: false,
  split_data: [],
  advance_fund_id: null,
  exclude_from_expense_basis: false,
  is_necessity: true,
});
const formError = ref('');
const saving = ref(false);

const existingBenefit = computed(() => props.income?.debt_payment_benefit_expense ?? null);

const expenseCategories = computed(() =>
  props.categories
    .filter((c) => c.is_expense)
    .slice()
    .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' })),
);

const selectedFundHasRemainingPercentageRule = computed(() => {
  if (form.value.advance_fund_id == null) {
    return false;
  }
  const fund = props.funds.find((f) => Number(f.id) === Number(form.value.advance_fund_id));
  return Boolean(fund?.has_remaining_percentage_rule);
});

const allowExpenseBasisExclusion = computed(
  () => allowsExpenseBasisExclusion(user.value?.closeout_mode),
);

watch(
  () => form.value.category_id,
  (categoryId) => {
    if (!categoryId) {
      return;
    }
    const cat = expenseCategories.value.find((c) => Number(c.id) === Number(categoryId));
    if (!cat) {
      return;
    }
    if (existingBenefit.value && Number(existingBenefit.value.category_id) === Number(categoryId)) {
      return;
    }
    form.value.is_necessity = cat.is_necessity_default !== false;
    if (cat.is_split_default && cat.split_default?.length) {
      form.value.is_split = true;
      form.value.split_data = props.familyUsers?.length
        ? equalSplitPayloadForFamilyUsers(props.familyUsers)
        : [];
    }
    if (cat.advance_fund_id) {
      form.value.advance_fund_id = cat.advance_fund_id;
    }
    if (
      allowExpenseBasisExclusion.value
      && cat.exclude_from_expense_basis_default
      && selectedFundHasRemainingPercentageRule.value
    ) {
      form.value.exclude_from_expense_basis = true;
    } else {
      form.value.exclude_from_expense_basis = false;
    }
  },
);

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
}

function toggleAdvanceFund() {
  if (form.value.advance_fund_id !== null) {
    form.value.advance_fund_id = null;
    form.value.exclude_from_expense_basis = false;
    return;
  }
  form.value.advance_fund_id = props.funds[0]?.id ?? null;
  form.value.exclude_from_expense_basis = false;
}

function resetFromIncome() {
  const benefit = existingBenefit.value;
  formError.value = '';
  if (benefit) {
    form.value = {
      category_id: benefit.category_id ?? null,
      description: benefit.description ?? '',
      is_split: Boolean(benefit.is_split),
      split_data: Array.isArray(benefit.split_data) ? benefit.split_data : (benefit.splits ?? []).map((s) => ({
        user_id: s.user_id,
        share_percentage: Number(s.share_percentage),
      })),
      advance_fund_id: benefit.advance_fund_id ?? null,
      exclude_from_expense_basis: Boolean(benefit.exclude_from_expense_basis),
      is_necessity: benefit.is_necessity !== false,
    };
    return;
  }

  form.value = {
    category_id: null,
    description: props.income?.description || '',
    is_split: false,
    split_data: equalSplitPayloadForFamilyUsers(props.familyUsers),
    advance_fund_id: null,
    exclude_from_expense_basis: false,
    is_necessity: true,
  };
}

watch(
  () => props.income?.id,
  () => {
    if (props.income) {
      resetFromIncome();
    }
  },
  { immediate: true },
);

watch(
  () => form.value.is_split,
  (isSplit) => {
    if (isSplit) {
      form.value.advance_fund_id = null;
      form.value.exclude_from_expense_basis = false;
      if (!form.value.split_data?.length) {
        form.value.split_data = equalSplitPayloadForFamilyUsers(props.familyUsers);
      }
    }
  },
);

watch(
  () => form.value.advance_fund_id,
  () => {
    if (!selectedFundHasRemainingPercentageRule.value) {
      form.value.exclude_from_expense_basis = false;
    }
  },
);

async function submit() {
  if (!props.income) {
    return;
  }
  if (!form.value.category_id) {
    formError.value = 'Please select a category.';
    return;
  }
  if (form.value.is_split) {
    const total = (form.value.split_data || []).reduce((sum, row) => sum + Number(row.share_percentage || 0), 0);
    if (Math.abs(total - 100) > 0.01) {
      formError.value = 'Split percentages must sum to 100%.';
      return;
    }
  }

  saving.value = true;
  formError.value = '';
  const payload = {
    category_id: form.value.category_id,
    description: form.value.description || null,
    is_split: Boolean(form.value.is_split),
    split_data: form.value.is_split ? form.value.split_data : null,
    advance_fund_id: form.value.is_split ? null : form.value.advance_fund_id,
    exclude_from_expense_basis: allowExpenseBasisExclusion.value && !form.value.is_split && Boolean(form.value.exclude_from_expense_basis),
    is_necessity: form.value.is_necessity !== false,
  };

  try {
    if (existingBenefit.value) {
      await put(`/transactions/${props.income.id}/debt-payment-benefit`, payload);
    } else {
      await post(`/transactions/${props.income.id}/debt-payment-benefit`, payload);
    }
    emit('saved');
  } catch (err) {
    formError.value = err.response?.data?.message
      || err.response?.data?.errors?.category_id?.[0]
      || 'Could not save the expense.';
  } finally {
    saving.value = false;
  }
}

async function removeBenefit() {
  if (!props.income || !existingBenefit.value) {
    return;
  }
  saving.value = true;
  formError.value = '';
  try {
    await del(`/transactions/${props.income.id}/debt-payment-benefit`);
    emit('removed');
  } catch (err) {
    formError.value = err.response?.data?.message || 'Could not remove the expense.';
  } finally {
    saving.value = false;
  }
}
</script>
