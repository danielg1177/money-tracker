<template>
  <form @submit.prevent="handleSubmit" class="space-y-6 pb-4">
    <!-- Type Toggle (Income / Expense) -->
    <div>
      <label class="block text-sm font-medium text-gray-300 mb-3">Type</label>
      <div class="grid grid-cols-2 gap-2">
        <button
          type="button"
          @click="form.type = 'expense'"
          :class="[
            'py-2 px-4 rounded-lg font-medium transition-colors',
            form.type === 'expense'
              ? 'bg-red-600 text-white'
              : 'bg-gray-800 text-gray-400 hover:bg-gray-700'
          ]"
        >
          Expense
        </button>
        <button
          type="button"
          @click="form.type = 'income'"
          :class="[
            'py-2 px-4 rounded-lg font-medium transition-colors',
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
          type="number"
          step="0.01"
          min="0"
          required
          :disabled="submitLoading"
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
        :disabled="submitLoading"
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
        :disabled="submitLoading"
        class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors disabled:opacity-50"
        placeholder="Add a note..."
      />
    </div>

    <!-- Date -->
    <div>
      <label for="date" class="block text-sm font-medium text-gray-300 mb-2">
        Date
      </label>
      <input
        id="date"
        v-model="form.transaction_date"
        type="date"
        required
        :disabled="submitLoading"
        class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors disabled:opacity-50"
      />
    </div>

    <!-- Split Toggle -->
    <div
      @click="form.is_split = !form.is_split"
      class="flex items-center justify-between p-3 bg-gray-800 border border-gray-700 rounded-lg cursor-pointer hover:border-gray-600 transition-colors"
    >
      <div>
        <p class="text-sm font-medium text-gray-300">Split between family members</p>
        <p class="text-xs text-gray-500 mt-0.5">Divide this transaction among family members</p>
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
    <div v-if="form.type === 'expense'" class="space-y-2">
      <div
        @click="toggleAdvanceFund"
        class="flex items-center justify-between p-3 bg-gray-800 border border-gray-700 rounded-lg cursor-pointer hover:border-gray-600 transition-colors"
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
    <div v-if="form.is_split">
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
        :disabled="submitLoading"
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
import SplitEditor from './SplitEditor.vue';

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
  transaction: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['created', 'updated', 'close']);

const { post, put, loading: submitLoading } = useApi();
const formError = ref(null);

const form = ref({
  type: 'expense',
  amount: null,
  category_id: null,
  description: '',
  transaction_date: new Date().toISOString().split('T')[0],
  is_split: false,
  split_data: [],
  advance_fund_id: null,
});

const filteredCategories = computed(() => {
  return props.categories.filter(cat => {
    if (form.value.type === 'income') {
      return cat.is_income;
    }
    return cat.is_expense;
  });
});

const selectedCategory = computed(() => {
  return filteredCategories.value.find(cat => cat.id === form.value.category_id);
});

const isEditMode = computed(() => !!props.transaction);

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
      form.value = {
        type: newTransaction.type,
        amount: parseFloat(newTransaction.amount),
        category_id: newTransaction.category_id,
        description: newTransaction.description || '',
        transaction_date: normalizeDateForInput(newTransaction.transaction_date),
        is_split: newTransaction.is_split,
        split_data: normalizeSplits(newTransaction.split_data || []),
        advance_fund_id: newTransaction.advance_fund_id ?? null,
      };
    } else {
      resetForm();
    }
  },
  { immediate: true }
);

watch(() => form.value.is_split, (newVal) => {
  if (!newVal) {
    form.value.split_data = [];
  }
});

watch(() => form.value.type, (newType) => {
  if (newType === 'income') {
    form.value.advance_fund_id = null;
  }
});

watch(() => form.value.category_id, () => {
  if (selectedCategory.value?.is_split_default && selectedCategory.value?.split_default?.length) {
    form.value.is_split = true;
    form.value.split_data = normalizeSplits(selectedCategory.value.split_default);
  }
  if (selectedCategory.value?.advance_fund_id) {
    form.value.advance_fund_id = selectedCategory.value.advance_fund_id;
  }
});

function resetForm() {
  form.value = {
    type: 'expense',
    amount: null,
    category_id: null,
    description: '',
    transaction_date: new Date().toISOString().split('T')[0],
    is_split: false,
    split_data: [],
    advance_fund_id: null,
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

  if (!form.value.amount || form.value.amount <= 0) {
    formError.value = 'Please enter a valid amount';
    return;
  }

  if (!form.value.category_id) {
    formError.value = 'Please select a category';
    return;
  }

  try {
    const payload = {
      type: form.value.type,
      amount: form.value.amount,
      category_id: form.value.category_id,
      description: form.value.description,
      transaction_date: form.value.transaction_date,
      is_split: form.value.is_split,
      advance_fund_id: form.value.type === 'expense' ? (form.value.advance_fund_id || null) : null,
      ...(form.value.is_split ? { split_data: form.value.split_data } : {}),
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
