<template>
  <div class="pb-32">
    <!-- Header -->
    <div class="sticky top-0 pt-safe bg-gray-900 border-b border-gray-800 px-4 py-3 z-10">
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-xl font-bold text-white">Closeout Rules</h1>
          <p class="text-gray-400 text-sm mt-1">
            {{ isFamilyPooled ? 'Family rules run first; your leftover rules split your share.' : 'Rules applied when a month is hard-closed' }}
          </p>
        </div>
        <button
          @click="openPersonalForm"
          class="inline-flex items-center gap-2 px-3 py-2 sm:px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors p-2"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          <span class="hidden sm:inline">{{ isFamilyPooled ? 'Add leftover rule' : 'Add Rule' }}</span>
        </button>
      </div>

      <div v-if="canManageFamily" class="mt-3">
        <p class="text-[11px] text-gray-500 mb-2">Applies to open months only. Hard-closed months keep whatever ran at close time.</p>
        <div class="grid grid-cols-2 gap-2">
          <button
            type="button"
            class="min-h-11 rounded-lg border px-3 py-2 text-sm font-medium"
            :class="closeoutMode === CLOSEOUT_MODE_CLASSIC ? 'border-blue-500 bg-blue-950/40 text-blue-100' : 'border-gray-700 bg-gray-800 text-gray-400'"
            @click="setCloseoutMode(CLOSEOUT_MODE_CLASSIC)"
          >
            Classic
          </button>
          <button
            type="button"
            class="min-h-11 rounded-lg border px-3 py-2 text-sm font-medium"
            :class="closeoutMode === CLOSEOUT_MODE_FAMILY_POOLED ? 'border-blue-500 bg-blue-950/40 text-blue-100' : 'border-gray-700 bg-gray-800 text-gray-400'"
            @click="setCloseoutMode(CLOSEOUT_MODE_FAMILY_POOLED)"
          >
            Family pooled
          </button>
        </div>
      </div>
      <p v-else-if="user?.family_id" class="mt-3 text-[11px] leading-snug text-gray-500">
        Classic / Family pooled is controlled by the head of household. You still see family rules when that mode is on.
      </p>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading rules...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ error }}</p>
      <button @click="fetchData" class="mt-2 text-xs text-red-400 hover:text-red-300 underline">
        Try again
      </button>
    </div>

    <!-- Empty State -->
    <div v-else-if="rules.length === 0 && familyRules.length === 0" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-12 h-12 text-gray-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-gray-400 font-medium">No closeout rules yet</p>
        <p class="text-gray-500 text-sm">Tap the + button to create your first rule</p>
      </div>
    </div>

    <div v-else class="px-4 py-4 space-y-6">
      <div v-if="isFamilyPooled">
        <div class="flex items-center justify-between gap-3 mb-3">
          <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide">Family rules</h2>
          <button
            v-if="canManageFamily"
            type="button"
            class="min-h-11 px-3 py-2 text-sm font-medium text-blue-300"
            @click="openFamilyForm"
          >
            Add family rule
          </button>
        </div>
        <p class="text-xs text-gray-500 mb-3">
          Surplus rules take a percent of family income minus necessary expenses. Remaining-after-charity rules run on what’s left after all expenses.
        </p>
        <div v-if="familyRules.length === 0" class="text-sm text-gray-500">No family rules yet.</div>
        <div v-else class="space-y-3">
          <div
            v-for="rule in familyRules"
            :key="'family-' + rule.id"
            class="bg-gray-800 border border-gray-700 rounded-xl p-3"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-xs font-bold bg-blue-600 text-white px-2 py-1 rounded">{{ rule.order }}</span>
                  <span class="text-sm font-medium text-gray-300">{{ rule.name }}</span>
                  <span class="text-[10px] uppercase tracking-wide text-gray-500">{{ rule.stage === 'surplus' ? 'Surplus' : 'After charity' }}</span>
                  <span :class="['w-2 h-2 rounded-full', rule.is_active ? 'bg-green-400' : 'bg-gray-500']"></span>
                </div>
                <div class="text-xs text-gray-400 space-y-1 mt-1">
                  <div>{{ formatFamilyAllocation(rule) }}</div>
                  <div>{{ getDestinationLabel(rule) }}</div>
                </div>
              </div>
              <div v-if="canManageFamily" class="flex gap-2 flex-shrink-0">
                <button @click="editFamilyRule(rule)" class="text-gray-400 hover:text-blue-400 transition-colors min-h-11 min-w-11 inline-flex items-center justify-center">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                </button>
                <button
                  v-if="deleteFamilyConfirm !== rule.id"
                  @click="deleteFamilyConfirm = rule.id"
                  class="text-gray-400 hover:text-red-400 transition-colors min-h-11 min-w-11 inline-flex items-center justify-center"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
                <button v-else @click="deleteFamilyRule(rule.id)" class="text-red-400 animate-pulse text-xs font-bold">
                  Confirm?
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div>
        <h2 v-if="isFamilyPooled" class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-2">Your leftover rules</h2>
        <p v-if="isFamilyPooled" class="text-xs text-gray-500 mb-3">
          These remaining-base rules run on your leftover share after family rules. Gross/net rules are skipped in family pooled mode.
        </p>
        <div v-if="visiblePersonalRules.length === 0" class="text-sm text-gray-500">{{ isFamilyPooled ? 'No leftover rules yet.' : 'No rules yet.' }}</div>
        <div v-else class="space-y-3">
      <div
        v-for="rule in visiblePersonalRules"
        :key="rule.id"
        class="bg-gray-800 border border-gray-700 rounded-xl p-3"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="text-xs font-bold bg-blue-600 text-white px-2 py-1 rounded">{{ rule.order }}</span>
              <span class="text-sm font-medium text-gray-300">{{ rule.name }}</span>
              <span :class="['w-2 h-2 rounded-full', rule.is_active ? 'bg-green-400' : 'bg-gray-500']"></span>
            </div>
            <div class="text-xs text-gray-400 space-y-1 mt-1">
              <div>{{ formatAllocation(rule) }}</div>
              <div :class="{ 'text-green-400': rule.destination_type === 'title' }">
                {{ getDestinationLabel(rule) }}
              </div>
              <div v-if="getCloseoutCategoryName(rule)" class="text-purple-300">
                Expense category: {{ getCloseoutCategoryName(rule) }}
              </div>
            </div>
          </div>
          <div class="flex gap-2 flex-shrink-0">
            <button
              @click="editRule(rule)"
              class="text-gray-400 hover:text-blue-400 transition-colors"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
            <button
              v-if="deleteConfirm !== rule.id"
              @click="confirmDelete(rule.id)"
              class="text-gray-400 hover:text-red-400 transition-colors"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
            <button
              v-else
              @click="deleteRule(rule.id)"
              class="text-red-400 animate-pulse text-xs font-bold"
            >
              Confirm?
            </button>
          </div>
        </div>
      </div>
      </div>
    </div>
    </div>

    <!-- Add/Edit Rule Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full"
      enter-to-class="translate-y-0"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0"
      leave-to-class="translate-y-full"
    >
      <div v-if="showForm" class="fixed inset-0 z-50">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50" @click="closeForm" />
        <!-- Modal -->
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[90vh] overflow-y-auto">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">{{ editingRule ? 'Edit Rule' : 'New Rule' }}</h2>
            <button @click="closeForm" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="p-4 space-y-4">
            <!-- Rule Name -->
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-1">Rule Name</label>
              <input
                v-model="formData.name"
                type="text"
                placeholder="e.g., Emergency Fund"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              />
            </div>

            <!-- Order -->
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-1">Order</label>
              <input
                v-model.number="formData.order"
                v-bind="mobileIntegerNumberAttrs"
                type="number"
                min="1"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              />
            </div>

            <!-- Allocation Type -->
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-1">Allocation Type</label>
              <select
                v-model="formData.allocation_type"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              >
                <option value="percentage">Percentage</option>
                <option value="fixed">Fixed Amount</option>
              </select>
            </div>

            <!-- Amount -->
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-1">
                {{ formData.allocation_type === 'percentage' ? 'Percentage (0-100)' : 'Amount ($)' }}
              </label>
              <input
                v-model.number="formData.amount"
                v-bind="mobileDecimalNumberAttrs"
                type="number"
                step="0.01"
                :placeholder="formData.allocation_type === 'percentage' ? '0-100' : '0.00'"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              />
            </div>

            <!-- Family stage -->
            <div v-if="formKind === 'family'">
              <label class="block text-sm font-medium text-gray-300 mb-1">Stage</label>
              <select
                v-model="formData.stage"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              >
                <option value="surplus">Surplus (income − necessary expenses)</option>
                <option value="remaining_after_charity">Remaining after charity</option>
              </select>
            </div>

            <!-- Applied To -->
            <div v-if="formKind === 'personal' && formData.allocation_type === 'percentage'">
              <label class="block text-sm font-medium text-gray-300 mb-1">Applied To</label>
              <select
                v-model="formData.allocation_base"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              >
                <option v-if="!isFamilyPooled" value="gross_income">Gross Income</option>
                <option value="remaining">{{ isFamilyPooled ? 'Your leftover share' : 'Remaining After Expenses' }}</option>
              </select>
            </div>

            <!-- Destination Type -->
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-1">Destination</label>
              <select
                v-model="formData.destination_type"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              >
                <option value="fund">Fund</option>
                <option value="debt">Debt</option>
                <option value="title">Save as Title</option>
              </select>
            </div>

            <!-- Destination ID (Fund or Debt) -->
            <div v-if="formData.destination_type === 'fund'">
              <label class="block text-sm font-medium text-gray-300 mb-1">Select Fund</label>
              <select
                v-model.number="formData.destination_id"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              >
                <option :value="null">-- Select a fund --</option>
                <optgroup v-if="formKind !== 'family'" label="Personal Funds">
                  <option v-for="fund in personalFunds" :key="fund.id" :value="fund.id">
                    {{ fund.name }}
                  </option>
                </optgroup>
                <optgroup v-if="familyFunds.length > 0" label="Family Funds">
                  <option v-for="fund in familyFunds" :key="fund.id" :value="fund.id">
                    {{ fund.name }}
                  </option>
                </optgroup>
              </select>
            </div>

            <div v-if="formData.destination_type === 'debt'">
              <label class="block text-sm font-medium text-gray-300 mb-1">Select Debt</label>
              <select
                v-model.number="formData.destination_id"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              >
                <option :value="null">-- Select a debt --</option>
                <option v-for="debt in userDebts" :key="debt.id" :value="debt.id">
                  {{ getDebtLabel(debt) }}
                </option>
              </select>
            </div>

            <!-- Title Input -->
            <div v-if="formData.destination_type === 'title'">
              <label class="block text-sm font-medium text-gray-300 mb-1">Title Name</label>
              <input
                v-model="formData.destination_title"
                type="text"
                placeholder="e.g., Medical Reserve"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-300 mb-1">Closeout Expense Category (optional)</label>
              <select
                v-model.number="formData.closeout_expense_category_id"
                class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
              >
                <option :value="null">-- Uncategorized --</option>
                <option v-for="category in expenseCategories" :key="category.id" :value="category.id">
                  {{ category.icon ? `${category.icon} ` : '' }}{{ category.name }}
                </option>
              </select>
            </div>

            <!-- Active Toggle -->
            <div class="flex items-center gap-3">
              <input
                v-model="formData.is_active"
                type="checkbox"
                id="is_active"
                class="w-4 h-4 bg-gray-800 border border-gray-700 rounded text-blue-600 focus:outline-none"
              />
              <label for="is_active" class="text-sm font-medium text-gray-300">Active</label>
            </div>

            <!-- Save Button -->
            <button
              @click="saveRule"
              class="w-full mt-6 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
            >
              {{ editingRule ? 'Update Rule' : 'Create Rule' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useApi } from '../composables/useApi';
import { useAuth } from '../composables/useAuth';
import { mobileDecimalNumberAttrs, mobileIntegerNumberAttrs } from '../support/mobileNumericInputAttrs.js';
import {
  CLOSEOUT_MODE_CLASSIC,
  CLOSEOUT_MODE_FAMILY_POOLED,
  isFamilyPooledCloseout,
  normalizeCloseoutMode,
} from '../support/closeoutMode.js';

const { get, post, put, del, loading, error } = useApi();
const { user } = useAuth();

const rules = ref([]);
const familyRules = ref([]);
const closeoutMode = ref(CLOSEOUT_MODE_CLASSIC);
const canManageFromApi = ref(null);
const canManageFamily = computed(() => {
  if (canManageFromApi.value !== null) {
    return canManageFromApi.value;
  }

  return Boolean(user.value?.can_manage_family || user.value?.canManageFamily);
});
const isFamilyPooled = computed(() => isFamilyPooledCloseout(closeoutMode.value));
const funds = ref([]);
const debts = ref({});
const categories = ref([]);
const showForm = ref(false);
const formKind = ref('personal');
const editingRule = ref(null);
const deleteConfirm = ref(null);
const deleteFamilyConfirm = ref(null);

const formData = ref({
  name: '',
  order: 1,
  allocation_type: 'percentage',
  amount: 0,
  allocation_base: 'gross_income',
  stage: 'surplus',
  destination_type: 'fund',
  destination_id: null,
  destination_title: '',
  closeout_expense_category_id: null,
  is_active: true,
});

const visiblePersonalRules = computed(() => {
  const sorted = [...rules.value].sort((a, b) => a.order - b.order);
  if (!isFamilyPooled.value) {
    return sorted;
  }

  return sorted.filter((rule) => rule.allocation_base === 'remaining');
});

const expenseCategories = computed(() => {
  return categories.value.filter(category => category.is_expense);
});

const userDebts = computed(() => {
  const allDebts = [];
  
  // Add personal debts where user is owed
  if (debts.value.owed) {
    allDebts.push(...debts.value.owed);
  }
  
  // Add personal debts where user owes (can also allocate to pay)
  if (debts.value.owing) {
    allDebts.push(...debts.value.owing);
  }
  
  // Add family debts
  if (debts.value.family_debts) {
    allDebts.push(...debts.value.family_debts);
  }
  
  return allDebts;
});

const personalFunds = computed(() => {
  return funds.value.filter(f => f.scope === 'personal' || !f.family_id);
});

const familyFunds = computed(() => {
  return funds.value.filter(f => f.scope === 'family' || f.family_id);
});

function formatAllocation(rule) {
  const amount = rule.allocation_type === 'percentage' ? `${rule.amount}%` : `$${Number(rule.amount).toFixed(2)}`;
  const base = rule.allocation_base === 'gross_income'
    ? 'Gross Income'
    : (isFamilyPooled.value ? 'your leftover share' : 'Remaining After Expenses');
  return `${amount} of ${base}`;
}

function formatFamilyAllocation(rule) {
  const amount = rule.allocation_type === 'percentage' ? `${rule.amount}%` : `$${Number(rule.amount).toFixed(2)}`;
  const base = rule.stage === 'surplus' ? 'surplus (income − necessary expenses)' : 'remaining after charity';
  return `${amount} of ${base}`;
}

function getDebtLabel(debt) {
  if (!debt) return 'Unknown Debt';
  
  // If has description, use it
  if (debt.description) {
    return debt.description;
  }
  
  // Build a label from available info
  if (debt.is_family_debt) {
    // Try creditor name first (who is owed to)
    if (debt.creditor?.name) {
      return `👥 ${debt.creditor.name}`;
    }
    // Fallback to creditor_name if creditor relationship not loaded
    if (debt.creditor_name) {
      return `👥 ${debt.creditor_name}`;
    }
    // Last resort: debtor name
    const name = debt.debtor?.name || 'Unknown';
    return `👥 ${name}`;
  }
  
  // Personal debts - try creditor first, then creditor_name
  if (debt.creditor?.name) {
    return debt.creditor.name;
  }
  
  if (debt.creditor_name) {
    return debt.creditor_name;
  }
  
  if (debt.debtor?.name) {
    return debt.debtor.name;
  }
  
  return `Debt #${debt.id}`;
}

function getDestinationLabel(rule) {
  if (rule.destination_type === 'fund') {
    const fund = funds.value.find(f => f.id === rule.destination_id);
    return `→ Fund: ${fund?.name || 'Unknown'}`;
  }
  if (rule.destination_type === 'debt') {
    const debt = userDebts.value.find(d => d.id === rule.destination_id);
    return `→ Debt: ${getDebtLabel(debt) || `#${rule.destination_id}`}`;
  }
  return `→ Save as: ${rule.destination_title}`;
}

function getCloseoutCategoryName(rule) {
  const categoryId = Number(rule?.closeout_expense_category_id);
  if (!categoryId) {
    return null;
  }

  const category = categories.value.find(c => Number(c.id) === categoryId);
  return category ? category.name : 'Unknown category';
}

async function fetchData() {
  try {
    const [rulesData, fundsData, debtsData, categoriesData] = await Promise.all([
      get('/closeout-rules'),
      get('/funds'),
      get('/debts'),
      get('/categories'),
    ]);
    rules.value = rulesData.sort((a, b) => a.order - b.order);
    funds.value = fundsData;
    debts.value = debtsData;
    categories.value = categoriesData;

    try {
      const settingsData = await get('/family/closeout-settings');
      closeoutMode.value = normalizeCloseoutMode(settingsData.closeout_mode);
      familyRules.value = (settingsData.family_rules || []).sort((a, b) => a.order - b.order);
      canManageFromApi.value = Boolean(settingsData.can_manage);
    } catch {
      error.value = null;
      closeoutMode.value = CLOSEOUT_MODE_CLASSIC;
      familyRules.value = [];
      canManageFromApi.value = false;
    }
  } catch (err) {
    console.error('Failed to fetch data:', err);
  }
}

async function setCloseoutMode(mode) {
  if (!canManageFamily.value || mode === closeoutMode.value) {
    return;
  }

  const confirmed = window.confirm(
    'This applies to open months only. Already hard-closed months stay as they were. Continue?'
  );
  if (!confirmed) {
    return;
  }

  try {
    const settingsData = await put('/family/closeout-settings', { closeout_mode: mode });
    closeoutMode.value = normalizeCloseoutMode(settingsData.closeout_mode || mode);
    familyRules.value = (settingsData.family_rules || []).sort((a, b) => a.order - b.order);
    canManageFromApi.value = Boolean(settingsData.can_manage);
  } catch (err) {
    console.error('Failed to update closeout mode:', err);
  }
}

function openPersonalForm() {
  formKind.value = 'personal';
  editingRule.value = null;
  formData.value.allocation_base = isFamilyPooled.value ? 'remaining' : 'gross_income';
  showForm.value = true;
}

function openFamilyForm() {
  formKind.value = 'family';
  editingRule.value = null;
  formData.value.stage = 'surplus';
  formData.value.destination_type = 'fund';
  showForm.value = true;
}

function editFamilyRule(rule) {
  formKind.value = 'family';
  editingRule.value = rule;
  formData.value = {
    name: rule.name,
    order: rule.order,
    allocation_type: rule.allocation_type,
    amount: rule.amount,
    allocation_base: 'remaining',
    stage: rule.stage,
    destination_type: rule.destination_type,
    destination_id: rule.destination_id,
    destination_title: rule.destination_title,
    closeout_expense_category_id: rule.closeout_expense_category_id,
    is_active: rule.is_active,
  };
  showForm.value = true;
}

function editRule(rule) {
  formKind.value = 'personal';
  editingRule.value = rule;
  formData.value = {
    name: rule.name,
    order: rule.order,
    allocation_type: rule.allocation_type,
    amount: rule.amount,
    allocation_base: rule.allocation_base,
    destination_type: rule.destination_type,
    destination_id: rule.destination_id,
    destination_title: rule.destination_title,
    closeout_expense_category_id: rule.closeout_expense_category_id,
    is_active: rule.is_active,
  };
  showForm.value = true;
}

async function saveRule() {
  try {
    const payload = {
      ...formData.value,
      order: parseInt(formData.value.order),
      amount: parseFloat(formData.value.amount),
    };

    if (formKind.value === 'family') {
      if (editingRule.value) {
        await put(`/family/closeout-rules/${editingRule.value.id}`, payload);
      } else {
        await post('/family/closeout-rules', payload);
      }
    } else if (editingRule.value) {
      await put(`/closeout-rules/${editingRule.value.id}`, payload);
    } else {
      await post('/closeout-rules', payload);
    }

    closeForm();
    await fetchData();
  } catch (err) {
    console.error('Failed to save rule:', err);
  }
}

function confirmDelete(ruleId) {
  deleteConfirm.value = deleteConfirm.value === ruleId ? null : ruleId;
}

async function deleteRule(ruleId) {
  try {
    await del(`/closeout-rules/${ruleId}`);
    deleteConfirm.value = null;
    await fetchData();
  } catch (err) {
    console.error('Failed to delete rule:', err);
  }
}

async function deleteFamilyRule(ruleId) {
  try {
    await del(`/family/closeout-rules/${ruleId}`);
    deleteFamilyConfirm.value = null;
    await fetchData();
  } catch (err) {
    console.error('Failed to delete family rule:', err);
  }
}

function closeForm() {
  showForm.value = false;
  editingRule.value = null;
  formKind.value = 'personal';
  formData.value = {
    name: '',
    order: 1,
    allocation_type: 'percentage',
    amount: 0,
    allocation_base: isFamilyPooled.value ? 'remaining' : 'gross_income',
    stage: 'surplus',
    destination_type: 'fund',
    destination_id: null,
    destination_title: '',
    closeout_expense_category_id: null,
    is_active: true,
  };
}

onMounted(fetchData);
</script>
