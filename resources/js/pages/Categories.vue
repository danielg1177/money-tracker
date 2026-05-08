<template>
  <div class="pb-32">
    <!-- Header -->
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-white">Categories</h1>
        <p class="text-gray-400 text-sm mt-1">Manage your family's categories</p>
      </div>
      <button
        @click="openAddModal"
        class="inline-flex items-center gap-2 px-3 py-2 sm:px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors p-2"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Add Category</span>
      </button>
    </div>

    <!-- Filter Tabs -->
    <div class="flex gap-2 px-4 py-4 border-b border-gray-800 bg-gray-900">
      <button
        @click="activeTypeFilter = 'expense'"
        :class="[
          'flex-1 py-2 px-3 rounded-full font-medium transition-colors',
          activeTypeFilter === 'expense'
            ? 'bg-blue-600 text-white'
            : 'bg-gray-800 text-gray-300 hover:bg-gray-600'
        ]"
      >
        Expense
      </button>
      <button
        @click="activeTypeFilter = 'income'"
        :class="[
          'flex-1 py-2 px-3 rounded-full font-medium transition-colors',
          activeTypeFilter === 'income'
            ? 'bg-blue-600 text-white'
            : 'bg-gray-800 text-gray-300 hover:bg-gray-600'
        ]"
      >
        Income
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading && !categories.length" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading categories...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ error }}</p>
      <button @click="fetchCategories" class="mt-2 text-xs text-red-400 hover:text-red-300 underline">
        Try again
      </button>
    </div>

    <!-- Empty State -->
    <div v-else-if="categories.length === 0" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-12 h-12 text-gray-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
        </svg>
        <p class="text-gray-400 font-medium">No categories yet</p>
        <p class="text-gray-500 text-sm">Click "Add Category" to create your first one</p>
      </div>
    </div>

    <!-- Empty Filter State -->
    <div v-else-if="filteredCategories.length === 0" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-12 h-12 text-gray-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
        </svg>
        <p class="text-gray-400 font-medium">
          No {{ activeTypeFilter }} categories yet
        </p>
        <p class="text-gray-500 text-sm">Click "Add Category" to create one</p>
      </div>
    </div>

    <!-- Categories List -->
    <div v-else class="space-y-3 px-4 py-4">
      <div
        v-for="category in filteredCategories"
        :key="category.id"
        class="bg-gray-800 border border-gray-700 rounded-xl p-3 flex items-stretch justify-between gap-3 min-h-[52px] touch-manipulation transition-colors hover:border-gray-600 active:bg-gray-800/80"
        role="button"
        tabindex="0"
        :aria-label="`Edit category ${category.name}`"
        @click="editCategory(category)"
        @keydown.enter.prevent="editCategory(category)"
        @keydown.space.prevent="editCategory(category)"
      >
        <div class="flex-1 min-w-0 self-center py-1 pr-2">
          <div class="flex items-center gap-2 mb-2">
            <span v-if="category.icon" class="text-xl">{{ category.icon }}</span>
            <span class="text-gray-200 font-medium truncate">{{ category.name }}</span>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <span
              class="px-2 py-1 text-xs font-medium rounded"
              :class="category.is_income ? 'bg-green-900/30 text-green-300' : 'bg-red-900/30 text-red-300'"
            >
              {{ category.is_income ? 'Income' : 'Expense' }}
            </span>
            <span v-if="category.is_split_default && category.is_expense" class="px-2 py-1 bg-purple-900/30 text-purple-300 text-xs font-medium rounded">
              Split Default
            </span>
            <span v-if="category.is_non_necessity_default && category.is_expense" class="px-2 py-1 bg-violet-900/30 text-violet-300 text-xs font-medium rounded">
              Non-Necessity Default
            </span>
          </div>
        </div>
        <div class="flex items-center shrink-0 self-start sm:self-center pt-1 sm:pt-0 border-l border-gray-700/50 pl-3 -mr-1" @click.stop>
          <button
            type="button"
            @click="deleteCategory(category)"
            class="p-3 -m-1 text-gray-400 hover:text-red-400 hover:bg-gray-700/80 rounded-lg transition-colors"
            title="Delete"
            aria-label="Delete category"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Add/Edit Category Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full"
      enter-to-class="translate-y-0"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0"
      leave-to-class="translate-y-full"
    >
      <div v-if="showAddModal || editingCategory" class="fixed inset-0 z-50">
        <!-- Backdrop -->
        <div
          class="absolute inset-0 bg-black/50"
          @click="closeModal"
        />
        <!-- Modal -->
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">
              {{ editingCategory ? 'Edit Category' : 'Add Category' }}
            </h2>
            <button
              @click="closeModal"
              class="text-gray-400 hover:text-white transition-colors"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <form @submit.prevent="handleSubmit" class="p-4 space-y-6">
            <!-- Name -->
            <div>
              <label for="name" class="block text-sm font-medium text-gray-300 mb-2">
                Name
              </label>
              <input
                id="name"
                v-model="form.name"
                type="text"
                required
                :disabled="submitting"
                class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors disabled:opacity-50"
                placeholder="e.g. Groceries"
              />
            </div>

            <!-- Icon -->
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">
                Icon
              </label>
              <IconPicker
                v-model="form.icon"
              />
            </div>

            <!-- Type: income or expense (mutually exclusive) -->
            <fieldset>
              <legend class="block text-sm font-medium text-gray-300 mb-2">
                Type
              </legend>
              <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 text-sm font-medium text-gray-300 cursor-pointer">
                  <input
                    v-model="categoryType"
                    type="radio"
                    value="income"
                    :disabled="submitting"
                    class="w-4 h-4 bg-gray-800 border border-gray-700 text-blue-600 focus:ring-blue-500 cursor-pointer disabled:opacity-50"
                  />
                  Income
                </label>
                <label class="flex items-center gap-2 text-sm font-medium text-gray-300 cursor-pointer">
                  <input
                    v-model="categoryType"
                    type="radio"
                    value="expense"
                    :disabled="submitting"
                    class="w-4 h-4 bg-gray-800 border border-gray-700 text-blue-600 focus:ring-blue-500 cursor-pointer disabled:opacity-50"
                  />
                  Expense
                </label>
              </div>
            </fieldset>

            <!-- Split default & advance fund: expense-only category settings -->
            <template v-if="categoryType === 'expense'">
              <div class="flex items-center gap-3">
                <input
                  id="is-split-default"
                  v-model="form.is_split_default"
                  type="checkbox"
                  :disabled="submitting"
                  class="w-4 h-4 bg-gray-800 border border-gray-700 rounded focus:ring-blue-500 cursor-pointer disabled:opacity-50"
                />
                <label for="is-split-default" class="text-sm font-medium text-gray-300 cursor-pointer">
                  Use as split default
                </label>
              </div>

              <div v-if="form.is_split_default">
                <label class="block text-sm font-medium text-gray-300 mb-3">
                  Default Split Distribution
                </label>
                <SplitEditor
                  :family-users="familyUsers"
                  :total-amount="100"
                  :initial-splits="form.split_default"
                  :mode="'percentage'"
                  @update:splits="form.split_default = $event"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">
                  Default Advance Fund <span class="text-gray-500">(optional)</span>
                </label>
                <select
                  v-model.number="form.advance_fund_id"
                  class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                >
                  <option :value="null">None</option>
                  <option v-for="fund in funds" :key="fund.id" :value="fund.id">
                    {{ fund.name }} ({{ fund.scope === 'family' || fund.family_id ? 'Family' : 'Personal' }})
                  </option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Expense transactions in this category will default to advancing against this fund</p>
              </div>

              <div
                v-if="form.advance_fund_id && selectedAdvanceFundHasNonNecessityRule"
                class="flex items-center gap-3"
              >
                <input
                  id="is-non-necessity-default"
                  v-model="form.is_non_necessity_default"
                  type="checkbox"
                  :disabled="submitting"
                  class="w-4 h-4 bg-gray-800 border border-gray-700 rounded focus:ring-violet-500 cursor-pointer disabled:opacity-50"
                />
                <label for="is-non-necessity-default" class="text-sm font-medium text-gray-300 cursor-pointer">
                  Default transactions as non-necessity
                </label>
              </div>
            </template>

            <!-- Error -->
            <div v-if="formError" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
              <p class="text-red-400 text-sm">{{ formError }}</p>
            </div>

            <!-- Submit Buttons -->
            <div class="flex gap-2 pt-4 border-t border-gray-700">
              <button
                type="button"
                @click="closeModal"
                :disabled="submitting"
                class="flex-1 py-2 px-4 bg-gray-800 hover:bg-gray-700 text-gray-300 font-medium rounded-lg transition-colors disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                :disabled="submitting"
                class="flex-1 py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors disabled:bg-gray-700 disabled:cursor-not-allowed flex items-center justify-center gap-2"
              >
                <span v-if="submitting">
                  <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                  </svg>
                  Saving...
                </span>
                <span v-else>
                  {{ editingCategory ? 'Update' : 'Create' }}
                </span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>

    <!-- Delete Confirmation Dialog -->
    <Transition
      enter-active-class="transition duration-200"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition duration-200"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div v-if="deleteConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 max-w-sm mx-4 space-y-4">
          <h3 class="text-lg font-semibold text-white">Delete Category?</h3>
          <p class="text-gray-400 text-sm">
            Are you sure you want to delete <span class="font-medium text-gray-300">{{ deleteConfirm.name }}</span>? This action cannot be undone.
          </p>
          <div class="flex gap-2 pt-4">
            <button
              @click="deleteConfirm = null"
              :disabled="deleting"
              class="flex-1 py-2 px-4 bg-gray-700 hover:bg-gray-600 text-gray-300 font-medium rounded-lg transition-colors disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              @click="confirmDelete"
              :disabled="deleting"
              class="flex-1 py-2 px-4 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
            >
              <span v-if="deleting">
                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
              </span>
              <span>Delete</span>
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { useApi } from '../composables/useApi';
import IconPicker from '../components/IconPicker.vue';
import SplitEditor from '../components/SplitEditor.vue';
import {
  equalSplitPayloadForFamilyUsers,
  hasPositiveSplitShares,
} from '../support/equalFamilySplit.js';

const { get, post, put, delete: apiDelete, loading } = useApi();

const categories = ref([]);
const familyUsers = ref([]);
const funds = ref([]);
const error = ref(null);
const formError = ref(null);
const showAddModal = ref(false);
const editingCategory = ref(null);
const submitting = ref(false);
const deleting = ref(false);
const deleteConfirm = ref(null);
const activeTypeFilter = ref('expense');
const categoryType = ref('expense');

const form = ref({
  name: '',
  icon: '',
  is_split_default: false,
  split_default: [],
  advance_fund_id: null,
  is_non_necessity_default: false,
});

const filteredCategories = computed(() => {
  if (activeTypeFilter.value === 'income') {
    return [...categories.value]
      .filter(cat => cat.is_income)
      .sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
  }

  return [...categories.value]
    .filter(cat => cat.is_expense)
    .sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
});

const selectedAdvanceFundHasNonNecessityRule = computed(() => {
  if (!form.value.advance_fund_id) return false;
  const fund = funds.value.find(f => f.id === form.value.advance_fund_id);
  return fund?.has_non_necessity_rule === true;
});

onMounted(() => {
  fetchCategories();
  fetchFunds();
});

watch(
  () => form.value.is_split_default,
  (on) => {
    if (!on) {
      form.value.split_default = [];

      return;
    }

    ensureEqualSplitDefaultsWhenEnabled();
  }
);

watch(
  () => familyUsers.value,
  () => {
    if (!form.value.is_split_default) {
      return;
    }
    ensureEqualSplitDefaultsWhenEnabled();
  },
  { deep: true }
);

watch(
  () => categoryType.value,
  (t) => {
    if (t !== 'expense') {
      form.value.is_split_default = false;
      form.value.split_default = [];
      form.value.is_non_necessity_default = false;
    }
  }
);

watch(() => form.value.advance_fund_id, (newVal) => {
  if (!newVal) {
    form.value.is_non_necessity_default = false;
  }
});

/** When split default is on and there is nothing to preserve, seed equal percentages for all family members. */
function ensureEqualSplitDefaultsWhenEnabled() {
  if (!familyUsers.value?.length) {
    return;
  }
  if (hasPositiveSplitShares(form.value.split_default)) {
    return;
  }
  form.value.split_default = equalSplitPayloadForFamilyUsers(familyUsers.value);
}

async function fetchCategories() {
  error.value = null;
  try {
    const [catData, usersData] = await Promise.all([
      get('/categories'),
      get('/family/users'),
    ]);
    categories.value = catData;
    familyUsers.value = usersData;
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to load categories';
    console.error('Failed to fetch categories:', err);
  }
}

async function fetchFunds() {
  try {
    const data = await get('/funds');
    funds.value = data;
  } catch (err) {
    console.error('Failed to fetch funds:', err);
  }
}

function resetForm() {
  categoryType.value = 'expense';
  form.value = {
    name: '',
    icon: '',
    is_split_default: false,
    split_default: [],
    advance_fund_id: null,
    is_non_necessity_default: false,
  };
}

function openAddModal() {
  editingCategory.value = null;
  formError.value = null;
  resetForm();
  categoryType.value = activeTypeFilter.value === 'income' ? 'income' : 'expense';
  showAddModal.value = true;
}

function editCategory(category) {
  editingCategory.value = category;
  categoryType.value = category.is_income ? 'income' : 'expense';
  const isExpense = categoryType.value === 'expense';
  form.value = {
    name: category.name,
    icon: category.icon,
    is_split_default: isExpense && category.is_split_default,
    split_default: isExpense ? (category.split_default || []) : [],
    advance_fund_id: isExpense ? (category.advance_fund_id || null) : null,
    is_non_necessity_default: isExpense ? (category.is_non_necessity_default || false) : false,
  };
  showAddModal.value = true;
}

function closeModal() {
  showAddModal.value = false;
  editingCategory.value = null;
  formError.value = null;
  resetForm();
}

async function handleSubmit() {
  formError.value = null;

  if (!form.value.name?.trim()) {
    formError.value = 'Category name is required';
    return;
  }

  submitting.value = true;

  try {
    const isIncome = categoryType.value === 'income';
    const isExpense = ! isIncome;
    const payload = {
      name: form.value.name.trim(),
      icon: form.value.icon || null,
      is_income: isIncome,
      is_expense: isExpense,
      is_split_default: isExpense && form.value.is_split_default,
      split_default: isExpense && form.value.is_split_default ? form.value.split_default : null,
      advance_fund_id: isExpense ? (form.value.advance_fund_id || null) : null,
      is_non_necessity_default: isExpense && !!form.value.advance_fund_id ? (form.value.is_non_necessity_default || false) : false,
    };

    if (editingCategory.value) {
      await put(`/categories/${editingCategory.value.id}`, payload);
    } else {
      await post('/categories', payload);
    }

    window.dispatchEvent(new CustomEvent('categories-changed'));
    await fetchCategories();
    closeModal();
  } catch (err) {
    formError.value = err.response?.data?.message || 'Failed to save category';
  } finally {
    submitting.value = false;
  }
}

function deleteCategory(category) {
  deleteConfirm.value = category;
}

async function confirmDelete() {
  if (!deleteConfirm.value) return;

  deleting.value = true;

  try {
    await apiDelete(`/categories/${deleteConfirm.value.id}`);
    window.dispatchEvent(new CustomEvent('categories-changed'));
    await fetchCategories();
    deleteConfirm.value = null;
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to delete category';
  } finally {
    deleting.value = false;
  }
}
</script>
