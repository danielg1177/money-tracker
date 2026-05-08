<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useApi } from '../composables/useApi';

const route = useRoute();
const router = useRouter();
const { get, post, del, loading, error } = useApi();

const summary = ref(null);
const currentUser = ref(null);
const isClosing = ref(false);
const selectedMonthFilter = ref(typeof route.params.yearMonth === 'string' ? route.params.yearMonth : '');

// Month label
const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const currentYear = computed(() => Number.parseInt(String(selectedMonthFilter.value).split('-')[0] || '0', 10));
const currentMonth = computed(() => Number.parseInt(String(selectedMonthFilter.value).split('-')[1] || '0', 10));
const monthLabel = computed(() => `${monthNames[(currentMonth.value || 1) - 1]} ${currentYear.value} Summary`);

const quickSelectMonths = computed(() => {
  const months = [];
  const cursor = new Date();
  cursor.setDate(1);
  cursor.setMonth(cursor.getMonth() + 2);

  for (let i = 0; i < 26; i += 1) {
    const year = cursor.getFullYear();
    const monthIndex = cursor.getMonth();
    const monthNumber = monthIndex + 1;
    months.push({
      label: `${monthNames[monthIndex]} ${year}`,
      value: `${year}-${String(monthNumber).padStart(2, '0')}`,
    });
    cursor.setMonth(cursor.getMonth() - 1);
  }

  return months;
});

function parseMonthValue(value) {
  return /^\d{4}-(0[1-9]|1[0-2])$/.test(String(value)) ? String(value) : '';
}

async function loadSummaryForSelectedMonth() {
  const monthValue = parseMonthValue(selectedMonthFilter.value);
  if (!monthValue) {
    summary.value = null;
    return;
  }
  const [year, month] = monthValue.split('-').map(Number);
  const response = await get(`/month-summary?year=${year}&month=${month}`);
  if (response) {
    summary.value = response;
  }
}

// Load data on mount
onMounted(async () => {
  await fetchCurrentUser();
  await loadSummaryForSelectedMonth();
});

watch(
  () => route.params.yearMonth,
  async (nextYearMonth) => {
    const parsed = parseMonthValue(nextYearMonth);
    if (!parsed) {
      return;
    }
    const shouldReload = parsed !== selectedMonthFilter.value || !summary.value;
    selectedMonthFilter.value = parsed;
    if (!shouldReload) {
      return;
    }
    await loadSummaryForSelectedMonth();
  }
);

async function handleMonthFilterChange() {
  const parsed = parseMonthValue(selectedMonthFilter.value);
  if (!parsed) {
    return;
  }

  // Always refresh immediately from the selected month value.
  await loadSummaryForSelectedMonth();

  if (parsed !== route.params.yearMonth) {
    await router.push(`/month-summary/${parsed}`);
  }
}

async function fetchCurrentUser() {
  try {
    currentUser.value = await get('/user');
  } catch (err) {
    console.error('Failed to fetch current user:', err);
  }
}

// Currency formatter
const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
};

// Lock icon states
const isHardClosed = computed(() => summary.value?.is_hard_closed === true);
const allSoftClosed = computed(() => summary.value?.close_status?.all_soft_closed === true);

// Format allocation (matching CloseoutRules.vue pattern)
function rulePreviewNet(rule) {
  return typeof rule.net_after_advances === 'number'
    ? rule.net_after_advances
    : rule.projected_amount;
}

function rulePreviewAdvanceBefore(rule) {
  return typeof rule.fund_advance_outstanding_before === 'number'
    ? rule.fund_advance_outstanding_before
    : 0;
}

function rulePreviewNetClass(amount) {
  if (amount < 0) {
    return 'text-amber-400';
  }
  if (amount > 0) {
    return 'text-green-400';
  }
  return 'text-gray-400';
}

const formatAllocation = (rule) => {
  if (rule.allocation_type === 'percentage') {
    const base = rule.allocation_base === 'gross_income'
      ? 'Gross Income'
      : rule.allocation_base === 'remaining'
        ? 'Remaining After Expenses'
        : 'Income';
    return `${rule.amount.toFixed(0)}% of ${base}`;
  } else {
    const base = rule.allocation_base === 'gross_income'
      ? 'Gross Income'
      : rule.allocation_base === 'remaining'
        ? 'Remaining After Expenses'
        : 'Income';
    return `${formatCurrency(rule.amount)} of ${base}`;
  }
};

// Get destination badge styling
const getDestinationColor = (type) => {
  if (type === 'fund') {
    return 'bg-blue-900 text-blue-300';
  }
  if (type === 'debt') {
    return 'bg-red-900 text-red-300';
  }
  return 'bg-green-900 text-green-300';
};

// Closeout state computations
const isUserSoftClosed = computed(() => {
  if (!summary.value?.close_status?.soft_closes) return false;
  return summary.value.close_status.soft_closes.some(sc => sc.user_id === currentUser.value?.id);
});

const canSoftClose = computed(() => {
  return !isHardClosed.value && !isUserSoftClosed.value;
});

const canUndoSoftClose = computed(() => {
  return !isHardClosed.value && isUserSoftClosed.value;
});

const canHardClose = computed(() => {
  return !isHardClosed.value && allSoftClosed.value && currentUser.value?.can_manage_family;
});

// Closeout functions
async function handleSoftClose() {
  try {
    isClosing.value = true;
    await post('/closeout/soft-close', { year: currentYear.value, month: currentMonth.value });
    await loadSummaryForSelectedMonth();
  } catch (err) {
    console.error('Failed to soft close:', err);
  } finally {
    isClosing.value = false;
  }
}

async function handleUndoSoftClose() {
  try {
    isClosing.value = true;
    await post('/closeout/undo-soft-close', { year: currentYear.value, month: currentMonth.value });
    await loadSummaryForSelectedMonth();
  } catch (err) {
    console.error('Failed to undo soft close:', err);
  } finally {
    isClosing.value = false;
  }
}

async function handleHardClose() {
  try {
    isClosing.value = true;
    await post('/closeout/hard-close', { year: currentYear.value, month: currentMonth.value });
    await loadSummaryForSelectedMonth();
  } catch (err) {
    console.error('Failed to hard close:', err);
  } finally {
    isClosing.value = false;
  }
}

async function handleCompleteTitleSaving(id) {
  try {
    const updated = await post(`/title-savings/${id}/complete`, {});
    if (summary.value?.title_savings) {
      const idx = summary.value.title_savings.findIndex(s => s.id === id);
      if (idx !== -1) {
        summary.value.title_savings[idx] = updated;
      }
    }
  } catch (err) {
    console.error('Failed to complete title saving:', err);
  }
}

async function handleIncompleteTitleSaving(id) {
  try {
    const updated = await del(`/title-savings/${id}/complete`);
    if (summary.value?.title_savings) {
      const idx = summary.value.title_savings.findIndex(s => s.id === id);
      if (idx !== -1) {
        summary.value.title_savings[idx] = updated;
      }
    }
  } catch (err) {
    console.error('Failed to incomplete title saving:', err);
  }
}

const TOP_CATEGORY_ROWS = 4;

const showAllExpenseCategories = ref(false);
const showAllIncomeCategories = ref(false);
const selectedCategory = ref(null);
const isCategoryTransactionsModalOpen = ref(false);
const splitHistoryModalRows = ref([]);
const splitHistoryModalTitle = ref('');
const isSplitHistoryModalOpen = ref(false);

function sortCategoriesByTotalDesc(rows) {
  return [...rows].sort((a, b) => Number(b.total) - Number(a.total));
}

const sortedExpenseCategories = computed(() => {
  const rows = (summary.value?.category_totals || []).filter(cat => cat.type === 'expense');
  return sortCategoriesByTotalDesc(rows);
});

const sortedIncomeCategories = computed(() => {
  const rows = (summary.value?.category_totals || []).filter(cat => cat.type === 'income');
  return sortCategoriesByTotalDesc(rows);
});

const displayedExpenseCategories = computed(() => {
  const list = sortedExpenseCategories.value;
  if (showAllExpenseCategories.value || list.length <= TOP_CATEGORY_ROWS) {
    return list;
  }
  return list.slice(0, TOP_CATEGORY_ROWS);
});

const displayedIncomeCategories = computed(() => {
  const list = sortedIncomeCategories.value;
  if (showAllIncomeCategories.value || list.length <= TOP_CATEGORY_ROWS) {
    return list;
  }
  return list.slice(0, TOP_CATEGORY_ROWS);
});

const hiddenExpenseCategoryCount = computed(() => {
  const n = sortedExpenseCategories.value.length;
  return n > TOP_CATEGORY_ROWS ? n - TOP_CATEGORY_ROWS : 0;
});

const hiddenIncomeCategoryCount = computed(() => {
  const n = sortedIncomeCategories.value.length;
  return n > TOP_CATEGORY_ROWS ? n - TOP_CATEGORY_ROWS : 0;
});

const expenseCategoriesTotal = computed(() =>
  sortedExpenseCategories.value.reduce((sum, cat) => sum + Number(cat.total || 0), 0),
);

const incomeCategoriesTotal = computed(() =>
  sortedIncomeCategories.value.reduce((sum, cat) => sum + Number(cat.total || 0), 0),
);

const fundMovementGroups = computed(() => {
  return summary.value?.fund_movements?.by_fund || [];
});

const debtRepaymentsReceived = computed(() => summary.value?.debt_repayments?.received ?? []);

const debtRepaymentsPaid = computed(() => summary.value?.debt_repayments?.paid ?? []);

const titleSavings = computed(() => summary.value?.title_savings ?? []);

const rulePreviewBasis = computed(() => summary.value?.rule_preview?.basis ?? null);

const remainingAfterExpenses = computed(() => Number(rulePreviewBasis.value?.remaining_after_expenses ?? 0));

const isNegativeRemainingAfterExpenses = computed(() => {
  if (!summary.value?.rule_preview?.basis) {
    return false;
  }
  return remainingAfterExpenses.value < -0.005;
});

const grossAllocationsTotal = computed(() => Number(rulePreviewBasis.value?.gross_allocations_total ?? 0));

const nonNecessityExpenses = computed(() => Number(rulePreviewBasis.value?.non_necessity_expenses ?? 0));

const hasNonNecessityTransactions = computed(() => nonNecessityExpenses.value > 0.005);

const showGrossAllocationsInPreview = computed(() => grossAllocationsTotal.value > 0.005);

const expenseCloseoutBasisLines = computed(() => summary.value?.rule_preview?.expense_closeout_basis?.lines ?? []);

function categoryTransactionsKey(category) {
  const categoryIdKey = category?.category_id === null || category?.category_id === undefined
    ? 'null'
    : String(category.category_id);

  return `${category?.type}_${categoryIdKey}`;
}

function openCategoryTransactions(category) {
  selectedCategory.value = category;
  isCategoryTransactionsModalOpen.value = true;
}

function closeCategoryTransactionsModal() {
  isCategoryTransactionsModalOpen.value = false;
}

function openSplitHistoryModal(balance, source) {
  const isFromYou = source === 'from_you_created';
  const rows = isFromYou
    ? (balance?.from_you_created_transactions ?? [])
    : (balance?.from_them_created_transactions ?? []);

  splitHistoryModalRows.value = Array.isArray(rows) ? rows : [];
  splitHistoryModalTitle.value = isFromYou
    ? `From your split transactions with ${balance?.user_name ?? 'member'}`
    : `From ${balance?.user_name ?? 'member'} split transactions`;
  isSplitHistoryModalOpen.value = true;
}

function closeSplitHistoryModal() {
  isSplitHistoryModalOpen.value = false;
  splitHistoryModalRows.value = [];
  splitHistoryModalTitle.value = '';
}

function splitSourceSignedAmount(balance, source) {
  const rawAmount = Number(balance?.[`${source}_amount`] || 0);
  if (!rawAmount) {
    return 0;
  }

  if (balance?.direction === 'they_owe_you') {
    return source === 'from_you_created' ? rawAmount : -rawAmount;
  }

  return source === 'from_you_created' ? -rawAmount : rawAmount;
}

function splitSourceAmountClass(balance, source) {
  const signedAmount = splitSourceSignedAmount(balance, source);
  if (signedAmount > 0.005) {
    return 'text-green-400';
  }
  if (signedAmount < -0.005) {
    return 'text-red-400';
  }
  return 'text-gray-200';
}

const splitHistoryGroups = computed(() => {
  if (!Array.isArray(splitHistoryModalRows.value) || splitHistoryModalRows.value.length === 0) {
    return [];
  }

  const grouped = new Map();

  splitHistoryModalRows.value.forEach((row) => {
    const categoryName = row?.category_name || 'Uncategorized';
    const categoryIcon = row?.category_icon || null;
    const key = `${categoryName}::${categoryIcon || ''}`;
    if (!grouped.has(key)) {
      grouped.set(key, {
        category_name: categoryName,
        category_icon: categoryIcon,
        rows: [],
      });
    }
    grouped.get(key).rows.push(row);
  });

  return [...grouped.values()]
    .map(group => ({
      ...group,
      rows: [...group.rows].sort((a, b) => String(a.transaction_date).localeCompare(String(b.transaction_date))),
    }))
    .sort((a, b) => String(a.category_name).localeCompare(String(b.category_name), undefined, { sensitivity: 'base' }));
});

const selectedCategoryTransactions = computed(() => {
  if (!selectedCategory.value) {
    return [];
  }

  const key = categoryTransactionsKey(selectedCategory.value);
  return summary.value?.category_transactions?.[key] ?? [];
});

function movementTypeLabel(type) {
  const labels = {
    allocation: 'Allocation',
    closeout_allocation: 'Closeout Allocation',
    borrow: 'Borrow',
    repayment: 'Repayment',
    initial_value: 'Initial Value Set At',
    advance_settlement: 'Advance Settlement',
  };
  return labels[type] || type;
}
</script>

<template>
  <div class="pb-32">
    <!-- Header -->
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <button
          @click="router.back()"
          class="p-1 hover:bg-gray-800 rounded-lg transition-colors"
          title="Go back"
        >
          <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <h1 class="text-lg font-semibold text-white">{{ monthLabel }}</h1>
      </div>

      <div class="flex items-center gap-2">
        <!-- Closeout Buttons -->
        <button
          v-if="canHardClose"
          type="button"
          @click="handleHardClose"
          :disabled="isClosing"
          class="px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-lg font-medium transition-colors disabled:opacity-50"
          title="Hard close month for all family members"
        >
          {{ isClosing ? 'Closing...' : 'Hard Close' }}
        </button>
        <button
          v-else-if="canUndoSoftClose"
          type="button"
          @click="handleUndoSoftClose"
          :disabled="isClosing"
          class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-gray-200 text-sm rounded-lg font-medium transition-colors disabled:opacity-50"
          title="Reopen your closeout for this month"
        >
          {{ isClosing ? 'Undoing...' : 'Undo' }}
        </button>
        <button
          v-else-if="canSoftClose"
          type="button"
          @click="handleSoftClose"
          :disabled="isClosing"
          class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg font-medium transition-colors disabled:opacity-50"
          title="Close out this month for yourself"
        >
          {{ isClosing ? 'Closing...' : 'Close Out' }}
        </button>

        <!-- Lock icon -->
        <svg
          v-if="isHardClosed"
          class="w-5 h-5 text-amber-400"
          title="Month is hard-closed"
          fill="currentColor"
          viewBox="0 0 20 20"
        >
          <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
        </svg>
        <svg
          v-else-if="allSoftClosed"
          class="w-5 h-5 text-blue-400"
          title="All members have closed out"
          fill="currentColor"
          viewBox="0 0 20 20"
        >
          <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
        </svg>
        <svg
          v-else
          class="w-5 h-5 text-gray-400"
          title="Month is open"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
        </svg>
      </div>
    </div>

    <div class="px-4 pt-3">
      <label for="month-summary-month-select" class="block text-xs text-gray-400 mb-1">View month</label>
      <select
        id="month-summary-month-select"
        v-model="selectedMonthFilter"
        @change="handleMonthFilterChange"
        class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:outline-none focus:border-blue-500"
      >
        <option
          v-for="monthOption in quickSelectMonths"
          :key="monthOption.value"
          :value="monthOption.value"
        >
          {{ monthOption.label }}
        </option>
      </select>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="flex flex-col items-center gap-2">
        <div class="w-6 h-6 border-2 border-gray-600 border-t-blue-400 rounded-full animate-spin" />
        <p class="text-xs text-gray-500">Loading summary...</p>
      </div>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="px-4 mt-6">
      <div class="bg-red-900/20 border border-red-800 rounded-lg px-4 py-3">
        <p class="text-sm text-red-300">{{ error }}</p>
      </div>
    </div>

    <!-- Content -->
    <div v-else-if="summary">
      <!-- Section 1a: Expenses (viewer-scoped; split rows use your share) -->
      <div class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-2">Your Expenses</h2>
        <p class="text-xs text-gray-500 mb-3">
          Debt repayments you pay use the transaction’s category when set; otherwise they appear under
          <span class="text-gray-300">Uncategorized Debt Payments</span>
          (same amounts feed <span class="text-gray-300">Projected closeout → Expenses</span>). Hard-close-generated ledger entries (fund transfers, debt payments) are excluded here; they appear in Fund In/Out and Debt Repayments below.
        </p>

        <div v-if="sortedExpenseCategories.length === 0" class="text-sm text-gray-500">
          No expenses for you this month
        </div>

        <div v-else class="space-y-2">
          <button
            v-for="cat in displayedExpenseCategories"
            :key="`${cat.type}-${cat.category_id}`"
            type="button"
            class="w-full text-left flex items-center justify-between px-3 py-2 bg-gray-800 rounded-lg border border-gray-700 hover:bg-gray-700 transition-colors min-h-11"
            @click="openCategoryTransactions(cat)"
          >
            <div class="flex items-center gap-2 min-w-0">
              <span v-if="cat.category_icon" class="text-sm shrink-0">
                {{ cat.category_icon }}
              </span>
              <span class="text-sm text-gray-300 truncate">{{ cat.category_name }}</span>
            </div>
            <span class="text-sm font-medium shrink-0 text-red-400">
              −{{ formatCurrency(cat.total) }}
            </span>
          </button>

          <button
            v-if="hiddenExpenseCategoryCount > 0"
            type="button"
            class="w-full min-h-11 py-2.5 px-3 text-sm font-medium text-blue-300 bg-gray-800/80 hover:bg-gray-800 border border-gray-700 rounded-lg transition-colors"
            @click="showAllExpenseCategories = !showAllExpenseCategories"
          >
            {{ showAllExpenseCategories ? 'Show top categories only' : `Show ${hiddenExpenseCategoryCount} more` }}
          </button>

          <div
            class="flex items-center justify-between gap-3 px-3 py-2.5 bg-gray-900/80 rounded-lg border border-gray-600"
          >
            <span class="text-sm font-semibold text-gray-200">Total expenses</span>
            <span class="text-sm font-semibold shrink-0 text-red-400 tabular-nums">
              −{{ formatCurrency(expenseCategoriesTotal) }}
            </span>
          </div>
          <template v-if="hasNonNecessityTransactions">
            <div class="flex items-center justify-between gap-3 px-3 py-2 bg-gray-900/50 rounded-lg border border-gray-700">
              <span class="text-sm text-gray-300">Total Necessities</span>
              <span class="text-sm font-medium shrink-0 text-red-400 tabular-nums">
                −{{ formatCurrency(expenseCategoriesTotal - nonNecessityExpenses) }}
              </span>
            </div>
            <div class="flex items-center justify-between gap-3 px-3 py-2 bg-gray-900/50 rounded-lg border border-gray-700">
              <span class="text-sm text-gray-300">Total Non-Necessities</span>
              <span class="text-sm font-medium shrink-0 text-violet-400 tabular-nums">
                −{{ formatCurrency(nonNecessityExpenses) }}
              </span>
            </div>
          </template>
        </div>
      </div>

      <!-- Section 1b: Income -->
      <div class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-2">Your Income</h2>
        <p class="text-xs text-gray-500 mb-3">
          Category totals exclude repayments received on tracked debts (shown under <span class="text-gray-300">Debt repayments</span>)
          and fund borrow withdrawals (shown under <span class="text-gray-300">Fund In/Out</span>). Both are excluded from <span class="text-gray-300">Gross Income</span> when closeout rules run.
        </p>

        <div v-if="sortedIncomeCategories.length === 0" class="text-sm text-gray-500">
          No income for you this month
        </div>

        <div v-else class="space-y-2">
          <button
            v-for="cat in displayedIncomeCategories"
            :key="`${cat.type}-${cat.category_id}`"
            type="button"
            class="w-full text-left flex items-center justify-between px-3 py-2 bg-gray-800 rounded-lg border border-gray-700 hover:bg-gray-700 transition-colors min-h-11"
            @click="openCategoryTransactions(cat)"
          >
            <div class="flex items-center gap-2 min-w-0">
              <span v-if="cat.category_icon" class="text-sm shrink-0">
                {{ cat.category_icon }}
              </span>
              <span class="text-sm text-gray-300 truncate">{{ cat.category_name }}</span>
            </div>
            <span class="text-sm font-medium shrink-0 text-green-400">
              +{{ formatCurrency(cat.total) }}
            </span>
          </button>

          <button
            v-if="hiddenIncomeCategoryCount > 0"
            type="button"
            class="w-full min-h-11 py-2.5 px-3 text-sm font-medium text-blue-300 bg-gray-800/80 hover:bg-gray-800 border border-gray-700 rounded-lg transition-colors"
            @click="showAllIncomeCategories = !showAllIncomeCategories"
          >
            {{ showAllIncomeCategories ? 'Show top categories only' : `Show ${hiddenIncomeCategoryCount} more` }}
          </button>

          <div
            class="flex items-center justify-between gap-3 px-3 py-2.5 bg-gray-900/80 rounded-lg border border-gray-600"
          >
            <span class="text-sm font-semibold text-gray-200">Total income</span>
            <span class="text-sm font-semibold shrink-0 text-green-400 tabular-nums">
              +{{ formatCurrency(incomeCategoriesTotal) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Split IOUs from shared expenses this month only (omit users with zero net after netting) -->
      <div v-if="summary.member_balances.length > 0" class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-2">Split balances (this month)</h2>
        <p class="text-xs text-gray-500 mb-3">
          From shared expense transactions dated this month (not repayments toward tracked debts or closeout ledger lines). Only members with a non-zero net for you appear.
        </p>

        <div class="space-y-2">
          <div
            v-for="balance in summary.member_balances"
            :key="balance.user_id"
            class="px-3 py-2 bg-gray-800 rounded-lg border border-gray-700 space-y-2"
          >
            <div class="flex items-center justify-between gap-2">
              <span class="text-sm text-gray-300 min-w-0 pr-2">
                <span v-if="balance.direction === 'they_owe_you'">
                  {{ balance.user_name }} owes you
                </span>
                <span v-else>
                  You owe {{ balance.user_name }}
                </span>
              </span>
              <span
                :class="balance.direction === 'they_owe_you' ? 'text-green-400' : 'text-red-400'"
                class="text-sm font-medium shrink-0 tabular-nums"
              >
                {{ formatCurrency(balance.net_amount) }}
              </span>
            </div>

            <div class="space-y-1.5 border-t border-gray-700/70 pt-2">
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs text-gray-400 min-w-0 pr-2">
                  From your created split transactions
                </span>
                <div class="flex items-center gap-2 shrink-0">
                  <span class="text-xs font-medium text-gray-200 tabular-nums">
                    <span :class="splitSourceAmountClass(balance, 'from_you_created')">
                      {{ splitSourceSignedAmount(balance, 'from_you_created') > 0.005 ? '+' : '' }}{{ formatCurrency(splitSourceSignedAmount(balance, 'from_you_created')) }}
                    </span>
                  </span>
                  <button
                    type="button"
                    class="px-2 py-1 text-[11px] rounded bg-gray-700 hover:bg-gray-600 text-gray-200 transition-colors"
                    @click="openSplitHistoryModal(balance, 'from_you_created')"
                  >
                    History
                  </button>
                </div>
              </div>

              <div class="flex items-center justify-between gap-2">
                <span class="text-xs text-gray-400 min-w-0 pr-2">
                  From {{ balance.user_name }} created split transactions
                </span>
                <div class="flex items-center gap-2 shrink-0">
                  <span class="text-xs font-medium text-gray-200 tabular-nums">
                    <span :class="splitSourceAmountClass(balance, 'from_them_created')">
                      {{ splitSourceSignedAmount(balance, 'from_them_created') > 0.005 ? '+' : '' }}{{ formatCurrency(splitSourceSignedAmount(balance, 'from_them_created')) }}
                    </span>
                  </span>
                  <button
                    type="button"
                    class="px-2 py-1 text-[11px] rounded bg-gray-700 hover:bg-gray-600 text-gray-200 transition-colors"
                    @click="openSplitHistoryModal(balance, 'from_them_created')"
                  >
                    History
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Section 1c: Debt repayments -->
      <div class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-2">Debt repayments</h2>
        <p class="text-xs text-gray-500 mb-3">
          Tracked repayments linked to debts. Creditor inbound rows do not trigger fund allocations or gross-income-based rules at hard close.
        </p>

        <div
          v-if="debtRepaymentsReceived.length === 0 && debtRepaymentsPaid.length === 0"
          class="text-sm text-gray-500"
        >
          No debt repayments this month
        </div>

        <div v-else class="space-y-4">
          <div v-if="debtRepaymentsReceived.length">
            <h3 class="text-xs font-medium text-sky-400 mb-2">Received (toward debts owed to you)</h3>
            <div class="space-y-2">
              <div
                v-for="row in debtRepaymentsReceived"
                :key="'dr-r-' + row.id"
                class="flex items-center justify-between gap-2 rounded-lg border border-sky-800/55 bg-gray-800 px-3 py-2"
              >
                <div class="min-w-0 flex-1">
                  <p class="text-[11px] text-gray-500">{{ row.transaction_date }}</p>
                  <p class="text-sm text-gray-200 truncate">
                    From {{ row.counterparty_label || 'debtor' }}
                  </p>
                  <p v-if="row.description" class="text-xs text-gray-400 truncate mt-0.5">{{ row.description }}</p>
                </div>
                <span class="text-sm font-semibold text-sky-400 shrink-0 tabular-nums">+{{ formatCurrency(row.amount) }}</span>
              </div>
            </div>
          </div>

          <div v-if="debtRepaymentsPaid.length">
            <h3 class="text-xs font-medium text-amber-300/90 mb-2">Paid (toward your debts)</h3>
            <div class="space-y-2">
              <div
                v-for="row in debtRepaymentsPaid"
                :key="'dr-p-' + row.id"
                class="flex items-center justify-between gap-2 rounded-lg border border-amber-900/45 bg-gray-800 px-3 py-2"
              >
                <div class="min-w-0 flex-1">
                  <p class="text-[11px] text-gray-500">{{ row.transaction_date }}</p>
                  <p class="text-sm text-gray-200 truncate">
                    Toward {{ row.counterparty_label || 'creditor' }}
                  </p>
                  <p v-if="row.description" class="text-xs text-gray-400 truncate mt-0.5">{{ row.description }}</p>
                </div>
                <span class="text-sm font-semibold text-amber-300 shrink-0 tabular-nums">-{{ formatCurrency(row.amount) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Section 2: Fund In/Out -->
      <div class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">Fund In/Out</h2>

        <div v-if="fundMovementGroups.length === 0" class="text-sm text-gray-500">
          No fund movement activity in this month.
        </div>

        <template v-else>
          <div class="px-3 py-2 mb-4 text-xs text-gray-400 bg-gray-800 rounded-lg">
            In: <span class="text-green-400">{{ formatCurrency(summary.fund_movements.totals.in) }}</span>
            | Out: <span class="text-amber-400">{{ formatCurrency(summary.fund_movements.totals.out) }}</span>
            | Net:
            <span :class="summary.fund_movements.totals.net >= 0 ? 'text-green-400' : 'text-amber-400'">
              {{ summary.fund_movements.totals.net >= 0 ? '+' : '' }}{{ formatCurrency(summary.fund_movements.totals.net) }}
            </span>
          </div>

          <div class="space-y-3">
            <div
              v-for="fundGroup in fundMovementGroups"
              :key="fundGroup.fund_id"
              class="bg-gray-800 rounded-xl p-3 border border-gray-700"
            >
              <div class="flex items-center justify-between gap-2 mb-2">
                <div class="min-w-0">
                  <p class="text-sm font-medium text-gray-200 truncate">{{ fundGroup.fund_name }}</p>
                  <p class="text-xs text-gray-500">{{ fundGroup.fund_scope === 'family' ? 'Family fund' : 'Personal fund' }}</p>
                </div>
                <span :class="fundGroup.totals.net >= 0 ? 'text-green-400' : 'text-amber-400'" class="text-sm font-semibold">
                  {{ fundGroup.totals.net >= 0 ? '+' : '' }}{{ formatCurrency(fundGroup.totals.net) }}
                </span>
              </div>

              <div class="space-y-1.5">
                <div
                  v-for="movement in fundGroup.movements"
                  :key="movement.id"
                  class="flex items-center justify-between gap-3 rounded-lg border border-gray-700 bg-gray-900/50 px-2.5 py-2"
                >
                  <div class="min-w-0">
                    <p class="text-xs text-gray-200 truncate">{{ movementTypeLabel(movement.type) }}</p>
                    <p v-if="movement.description" class="text-[11px] text-gray-500 truncate">{{ movement.description }}</p>
                  </div>
                  <span :class="movement.direction === 'out' ? 'text-amber-400' : 'text-green-400'" class="text-xs font-semibold shrink-0">
                    {{ movement.direction === 'out' ? '−' : '+' }}{{ formatCurrency(movement.amount) }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- Section 4: Closeout Rules Preview -->
      <div class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">
          {{ isHardClosed ? 'Closeout Results' : 'Projected Closeout' }}
        </h2>

        <div v-if="summary.rule_preview.basis.gross_income <= 0" class="text-sm text-gray-500 mb-4">
          No income recorded — closeout rules will not run.
        </div>

        <template v-else>
          <div
            v-if="isNegativeRemainingAfterExpenses"
            class="mb-4 rounded-lg border border-amber-800/70 bg-amber-950/40 px-3 py-3"
            role="status"
          >
            <p class="text-sm font-medium text-amber-200">
              Remaining is negative this month
            </p>
            <p class="mt-1 text-xs text-amber-100/90 leading-relaxed">
              After gross income, gross-base closeout allocations, and eligible expenses, the amount left for
              “remaining after expenses” rules is below zero. Rules that use that pool allocate nothing until the
              math is positive; consider adjusting income, spending, or your closeout rules.
            </p>
          </div>

          <div
            v-if="expenseCloseoutBasisLines.length > 0"
            class="mb-4 rounded-lg border border-gray-700 bg-gray-800/60 px-3 py-3"
          >
            <p class="text-xs font-semibold text-gray-300 uppercase tracking-wide mb-2">
              How expenses count here
            </p>
            <ul class="list-disc space-y-1.5 pl-4 text-xs text-gray-400 leading-relaxed">
              <li v-for="(line, idx) in expenseCloseoutBasisLines" :key="'ecb-' + idx">
                {{ line }}
              </li>
            </ul>
            <p class="mt-2 text-xs text-gray-500 leading-relaxed">
              <span class="text-gray-300">Remaining</span>
              uses gross income, minus the
              <span class="text-gray-300">Gross-base rules</span>
              figure above (rules on
              <span class="text-gray-300">gross</span>
              /
              <span class="text-gray-300">net income</span>
              bases; fund destinations net out month advances tagged to that fund so advance expenses are not double-counted), minus this expense total.
            </p>
          </div>

          <!-- Summary row -->
          <div class="px-3 py-2 mb-4 text-xs text-gray-400 bg-gray-800 rounded-lg space-y-1.5">
            <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
              <span>Gross Income: <span class="text-gray-200 tabular-nums">{{ formatCurrency(summary.rule_preview.basis.gross_income) }}</span></span>
              <span class="text-gray-600" aria-hidden="true">|</span>
              <span>{{ hasNonNecessityTransactions ? 'Necessity Expenses:' : 'Expenses:' }} <span class="text-gray-200 tabular-nums">{{ formatCurrency(summary.rule_preview.basis.total_expenses) }}</span></span>
              <template v-if="showGrossAllocationsInPreview">
                <span class="text-gray-600" aria-hidden="true">|</span>
                <span>Gross-base rules: <span class="text-gray-200 tabular-nums">−{{ formatCurrency(grossAllocationsTotal) }}</span></span>
              </template>
              <span class="text-gray-600" aria-hidden="true">|</span>
              <span>Remaining: <span
                class="tabular-nums font-medium"
                :class="isNegativeRemainingAfterExpenses ? 'text-amber-300' : 'text-gray-200'"
              >{{ formatCurrency(remainingAfterExpenses) }}</span></span>
            </div>
          </div>

          <!-- Rules list -->
          <div v-if="summary.rule_preview.rules.length === 0" class="text-sm text-gray-500">
            No active closeout rules configured.
          </div>

          <div v-else class="space-y-2">
            <div
              v-for="rule in summary.rule_preview.rules"
              :key="rule.rule_id"
              class="bg-gray-800 rounded-xl p-3 border border-gray-700"
            >
              <!-- Rule header: order badge + name -->
              <div class="flex items-start gap-2 mb-2">
                <span class="inline-flex items-center justify-center w-6 h-6 bg-gray-700 text-gray-300 text-xs font-semibold rounded">
                  {{ rule.order }}
                </span>
                <span class="text-sm font-medium text-gray-200">{{ rule.rule_name }}</span>
              </div>

              <!-- Allocation description + net to destination (fund rules net out month advances tagging that fund) -->
              <div class="flex items-center justify-between px-2 mb-2 gap-3">
                <span class="text-xs text-gray-400">{{ formatAllocation(rule) }}</span>
                <div class="flex flex-col items-end gap-0.5 shrink-0 min-w-0">
                  <span
                    :class="rulePreviewNetClass(rulePreviewNet(rule))"
                    class="text-sm font-medium tabular-nums"
                  >
                    {{ formatCurrency(rulePreviewNet(rule)) }}
                  </span>
                  <span
                    v-if="rule.destination_type === 'fund' && rulePreviewAdvanceBefore(rule) >= 0.005"
                    class="text-[10px] text-gray-500 text-right leading-tight tabular-nums max-w-[12rem]"
                  >
                    Rule {{ formatCurrency(rule.projected_amount) }} − advances tagged to fund {{ formatCurrency(rulePreviewAdvanceBefore(rule)) }}
                  </span>
                </div>
              </div>

              <!-- Destination badge -->
              <div class="flex items-center gap-2 px-2">
                <span class="text-xs text-gray-600">→</span>
                <span
                  :class="getDestinationColor(rule.destination_type)"
                  class="text-xs px-2 py-1 rounded-full font-medium"
                >
                  {{ rule.destination_name }}
                </span>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- Section 5: Title Savings (hard-closed months only) -->
      <div v-if="isHardClosed && titleSavings.length > 0" class="px-4 mt-6 pb-4">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-1">Title Savings</h2>
        <p class="text-xs text-gray-500 mb-3">
          Money set aside by your closeout rules. Mark complete when you have physically
          transferred or spent this amount. Completing a title saving reduces your tracked bank balance.
        </p>
        <div class="space-y-2">
          <div
            v-for="saving in titleSavings"
            :key="saving.id"
            class="flex items-center justify-between gap-3 px-3 py-3 bg-gray-800 rounded-xl border"
            :class="saving.is_completed ? 'border-green-800/60' : 'border-gray-700'"
          >
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm font-medium text-gray-200 truncate">{{ saving.title }}</span>
                <span
                  v-if="saving.is_completed"
                  class="shrink-0 inline-block px-2 py-0.5 bg-green-900 text-green-300 text-xs rounded-full font-medium"
                >
                  Done
                </span>
              </div>
              <p
                class="text-sm font-semibold mt-0.5"
                :class="saving.is_completed ? 'text-green-400' : 'text-gray-300'"
              >
                {{ formatCurrency(saving.amount) }}
              </p>
              <p v-if="saving.completed_at" class="text-xs text-gray-500 mt-0.5">
                Completed
                {{ new Date(saving.completed_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) }}
              </p>
            </div>
            <div class="shrink-0">
              <button
                v-if="!saving.is_completed"
                type="button"
                class="px-3 py-2 bg-green-700 hover:bg-green-600 text-white text-xs font-medium rounded-lg transition-colors"
                @click="handleCompleteTitleSaving(saving.id)"
              >
                Mark Done
              </button>
              <button
                v-else
                type="button"
                class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-gray-300 text-xs font-medium rounded-lg transition-colors"
                @click="handleIncompleteTitleSaving(saving.id)"
              >
                Undo
              </button>
            </div>
          </div>
        </div>
      </div>

      <Teleport to="body">
        <div
          v-if="isCategoryTransactionsModalOpen"
          class="fixed inset-0 z-50 bg-black/70"
          @click.self="closeCategoryTransactionsModal"
        >
          <div class="fixed inset-x-0 bottom-0 max-h-[82vh] overflow-hidden rounded-t-2xl border-t border-gray-700 bg-gray-900">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
              <div class="min-w-0">
                <h3 class="text-sm font-semibold text-gray-100 truncate">
                  {{ selectedCategory?.category_name }}
                </h3>
                <p class="text-xs text-gray-400">
                  {{ monthLabel.replace(' Summary', '') }} transactions
                </p>
              </div>
              <button
                type="button"
                class="rounded-lg p-2 text-gray-400 hover:bg-gray-800 hover:text-gray-200 transition-colors"
                @click="closeCategoryTransactionsModal"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <div class="overflow-y-auto px-4 py-3 space-y-2 max-h-[calc(82vh-4rem)]">
              <div v-if="selectedCategoryTransactions.length === 0" class="text-sm text-gray-500 py-8 text-center">
                No transactions found for this category in this month.
              </div>

              <div
                v-else
                v-for="row in selectedCategoryTransactions"
                :key="`cat-row-${row.id}-${row.transaction_date}-${row.amount}`"
                class="flex items-center justify-between gap-3 rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5"
              >
                <div class="min-w-0">
                  <p class="text-xs text-gray-500">{{ row.transaction_date }}</p>
                  <p class="text-sm text-gray-200 truncate">
                    {{ row.description || selectedCategory?.category_name || 'No description' }}
                  </p>
                  <div v-if="row.is_split" class="mt-1">
                    <p class="text-[11px] text-purple-300">Split transaction</p>
                    <div
                      v-for="splitRow in row.split_breakdown || []"
                      :key="`split-${row.id}-${splitRow.user_id}`"
                      class="text-[11px] text-gray-400 leading-snug"
                    >
                      {{ splitRow.user_name }} · {{ splitRow.share_percentage }}% · {{ formatCurrency(splitRow.amount) }}
                    </div>
                  </div>
                </div>
                <span
                  class="text-sm font-medium shrink-0 tabular-nums"
                  :class="selectedCategory?.type === 'expense' ? 'text-red-400' : 'text-green-400'"
                >
                  {{ selectedCategory?.type === 'expense' ? '−' : '+' }}{{ formatCurrency(row.amount) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </Teleport>

      <Teleport to="body">
        <div
          v-if="isSplitHistoryModalOpen"
          class="fixed inset-0 z-50 bg-black/70"
          @click.self="closeSplitHistoryModal"
        >
          <div class="fixed inset-x-0 bottom-0 max-h-[82vh] overflow-hidden rounded-t-2xl border-t border-gray-700 bg-gray-900">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
              <div class="min-w-0">
                <h3 class="text-sm font-semibold text-gray-100 truncate">
                  {{ splitHistoryModalTitle }}
                </h3>
                <p class="text-xs text-gray-400">
                  {{ monthLabel.replace(' Summary', '') }} split transaction history
                </p>
              </div>
              <button
                type="button"
                class="rounded-lg p-2 text-gray-400 hover:bg-gray-800 hover:text-gray-200 transition-colors"
                @click="closeSplitHistoryModal"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <div class="overflow-y-auto px-4 py-3 space-y-2 max-h-[calc(82vh-4rem)]">
              <div v-if="splitHistoryGroups.length === 0" class="text-sm text-gray-500 py-8 text-center">
                No split transactions found for this source.
              </div>

              <div
                v-else
                v-for="group in splitHistoryGroups"
                :key="`split-hist-group-${group.category_name}-${group.category_icon || 'none'}`"
                class="space-y-2"
              >
                <div class="flex items-center gap-2 px-1">
                  <span v-if="group.category_icon" class="text-sm shrink-0">{{ group.category_icon }}</span>
                  <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-300 truncate">
                    {{ group.category_name }}
                  </h4>
                </div>

                <div
                  v-for="row in group.rows"
                  :key="`split-hist-${row.transaction_id}`"
                  class="flex items-center justify-between gap-3 rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5"
                >
                  <div class="min-w-0">
                    <p class="text-xs text-gray-500">{{ row.transaction_date }}</p>
                    <p v-if="row.description" class="text-sm text-gray-200 truncate">{{ row.description }}</p>
                    <p v-else class="text-sm text-gray-300 truncate">{{ row.category_name }}</p>
                    <p class="text-[11px] text-gray-500 mt-0.5">
                      Total {{ formatCurrency(row.total_amount) }}
                    </p>
                  </div>
                  <span class="text-sm font-medium text-gray-200 shrink-0 tabular-nums">
                    {{ formatCurrency(row.balance_amount) }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Teleport>
    </div>
  </div>
</template>
