<template>
  <div class="pb-32">
    <!-- Header -->
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-white">Transactions</h1>
        <p class="text-gray-400 text-sm mt-1">Manage your spending and income</p>
      </div>
      <div
        v-if="showCloseOutHeaderButton"
        class="shrink-0 pt-0.5"
      >
        <button
          type="button"
          @click="isUserSoftClosed ? handleUndoSoftClose() : handleSoftClose()"
          :class="[
            'max-w-[11rem] sm:max-w-none text-right sm:text-left rounded-lg text-xs sm:text-sm font-medium transition-colors px-3 py-2 leading-tight',
            isUserSoftClosed
              ? 'bg-gray-700 hover:bg-gray-600 text-gray-300'
              : 'bg-blue-600 hover:bg-blue-700 text-white'
          ]"
        >
          {{ isUserSoftClosed ? 'Undo' : 'Close Out' }}
        </button>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-gray-900 border-b border-gray-800 px-4 py-3 space-y-3">
      <div class="flex items-stretch gap-2">
        <div
          v-if="currentMonthYear && selectedMonthFilter !== 'custom'"
          class="shrink-0 flex w-11 items-center justify-center rounded-lg border bg-gray-800"
          :class="monthLockUi.borderClass"
          :title="monthLockUi.title"
        >
          <!-- Hard-closed: locked -->
          <svg
            v-if="isCurrentMonthHardClosed"
            class="h-5 w-5 text-amber-400"
            fill="currentColor"
            viewBox="0 0 20 20"
            aria-hidden="true"
          >
            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
          </svg>
          <!-- You've marked your month; not hard-closed yet -->
          <svg
            v-else-if="isUserSoftClosed"
            class="h-5 w-5 text-blue-400"
            fill="currentColor"
            viewBox="0 0 20 20"
            aria-hidden="true"
          >
            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
          </svg>
          <!-- Open for you -->
          <svg
            v-else
            class="h-5 w-5 text-gray-400"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
          </svg>
        </div>
        <select
          v-model="selectedMonthFilter"
          @change="handleMonthFilterChange"
          class="min-w-0 grow bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:outline-none focus:border-blue-500"
        >
          <optgroup label="Quick Select">
            <option
              v-for="month in quickSelectMonths"
              :key="month.value"
              :value="month.value"
              :class="{ 'text-gray-500': isMonthClosed(month.value.split('-')[0], month.value.split('-')[1]) }"
            >
              {{ month.label }}
            </option>
          </optgroup>
          <optgroup label="Custom">
            <option value="custom">Custom Range</option>
          </optgroup>
        </select>
        <button
          v-if="selectedMonthFilter && selectedMonthFilter !== 'custom'"
          type="button"
          @click="navigateToMonthSummary"
          class="shrink-0 px-3 py-2 bg-gray-700 hover:bg-gray-600 text-gray-200 text-sm rounded-lg font-medium transition-colors"
        >
          View
        </button>
      </div>

      <!-- Custom Date Range Inputs -->
      <div v-if="selectedMonthFilter === 'custom'" class="flex gap-2 items-end">
        <div class="flex-1">
          <label class="block text-xs text-gray-400 mb-1">From</label>
          <input
            v-model="customStartDate"
            type="date"
            class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:outline-none focus:border-blue-500"
          />
        </div>
        <div class="flex-1">
          <label class="block text-xs text-gray-400 mb-1">To</label>
          <input
            v-model="customEndDate"
            type="date"
            class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:outline-none focus:border-blue-500"
          />
        </div>
        <button
          @click="applyCustomRange"
          class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg font-medium transition-colors"
        >
          Apply
        </button>
      </div>
    </div>

    <!-- Period totals (current filter range; shown even when there are no rows) -->
    <div
      v-if="!loading && !error"
      class="mx-4 mt-3 rounded-xl border border-gray-700 bg-gray-800 p-3"
    >
      <div class="flex justify-between gap-4">
        <div>
          <p class="text-xs font-medium uppercase text-gray-400">Income</p>
          <p class="font-semibold text-green-400">+{{ formatCurrency(totalIncome) }}</p>
        </div>
        <div class="text-right">
          <p class="text-xs font-medium uppercase text-gray-400">Expenses</p>
          <p class="font-semibold text-red-400">−{{ formatCurrency(totalExpenses) }}</p>
        </div>
      </div>
      <p class="mt-2 border-t border-gray-700/60 pt-2 text-center text-[10px] text-gray-500 leading-snug">
        Split <span class="text-gray-400">expenses</span> use <span class="text-gray-400">your share</span> in the expense total and in each day’s expense sum.
        <span class="block mt-1 text-gray-500">Income totals exclude <span class="text-sky-300/90">debt repayments</span> received (they do not count as earned income for closeout).</span>
      </p>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading transactions...</p>
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
    <div v-else-if="transactions.length === 0" class="flex items-center justify-center py-8">
      <div class="text-center">
        <svg class="w-12 h-12 text-gray-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m0 0h6m0 0V6m0 0H6m0 0V3" />
        </svg>
        <p class="text-gray-400 font-medium">No transactions for this period</p>
        <p class="text-gray-500 text-sm">Tap the + button to add a transaction</p>
      </div>
    </div>

    <!-- Transactions List (grouped by day) -->
    <div v-if="!loading && transactions.length > 0" class="space-y-0 px-0 py-4">
      <div v-for="dayGroup in transactionsByDay" :key="dayGroup.date">
        <!-- Day Header -->
        <div class="flex items-center justify-between px-4 py-1.5 mt-2">
          <span class="text-[10px] sm:text-sm font-semibold text-gray-400">
            {{ formatDate(dayGroup.date) }}
          </span>
          <div class="flex items-center gap-4">
            <span v-if="dayGroup.totalIncome > 0" class="text-[10px] sm:text-xs font-medium text-green-400">
              +{{ formatCurrency(dayGroup.totalIncome) }}
            </span>
            <span v-if="dayGroup.totalExpenses > 0" class="text-[10px] sm:text-xs font-medium text-red-400">
              −{{ formatCurrency(dayGroup.totalExpenses) }}
            </span>
          </div>
        </div>

        <!-- Day's Transactions -->
        <div class="space-y-2 px-4 py-1.5">
          <div
            v-for="transaction in dayGroup.transactions"
            :key="transaction.id"
            :class="[
              'bg-gray-800 border border-gray-700 rounded-lg sm:rounded-xl p-2 sm:p-3 transition-colors',
              transaction.is_closeout_initiated ? 'border-purple-600/40 bg-purple-900/10' : '',
              confirmDelete[transaction.id] ? 'border-red-600 bg-red-900/20' : '',
              isCurrentMonthHardClosed ? 'opacity-75' : '',
              !confirmDelete[transaction.id] && !isCurrentMonthHardClosed && isSystemCloseoutEntry(transaction) ? 'cursor-default' : '',
              !confirmDelete[transaction.id] && !isCurrentMonthHardClosed && !isSystemCloseoutEntry(transaction) ? 'cursor-pointer hover:border-gray-600' : '',
            ]"
            @click="!confirmDelete[transaction.id] && !isCurrentMonthHardClosed && !isSystemCloseoutEntry(transaction) && openEditForm(transaction.id)"
          >
            <!-- Main transaction row: one horizontal row on all breakpoints so amount + split stay beside title on mobile -->
            <div class="flex min-w-0 flex-row items-start justify-between gap-2 sm:gap-3">
              <div
                class="min-w-0 flex-1"
                :class="!confirmDelete[transaction.id] && !isCurrentMonthHardClosed && !isSystemCloseoutEntry(transaction) && 'cursor-pointer'"
              >
                <div class="flex items-center gap-1.5 sm:gap-2 mb-0.5 sm:mb-1 min-w-0 flex-wrap">
                  <span
                    v-if="transaction.category?.icon"
                    class="text-sm sm:text-base shrink-0"
                  >
                    {{ transaction.category.icon }}
                  </span>
                  <span class="text-[11px] sm:text-base text-gray-300 font-medium truncate min-w-0 flex-1 leading-tight">
                    {{ getTransactionCategoryLabel(transaction) }}
                  </span>
                  <span
                    v-for="pill in transactionKindPills(transaction)"
                    :key="pill.key"
                    :title="pill.title || undefined"
                    class="inline-flex shrink-0 items-center rounded-md px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide"
                    :class="pill.classes"
                  >
                    {{ pill.label }}
                  </span>
                </div>
                <p v-if="transaction.description" class="hidden sm:block text-gray-400 text-xs truncate">
                  {{ transaction.description }}
                </p>
                <div v-if="transaction.user?.name" class="hidden sm:block text-xs text-gray-500 mt-1.5">
                  {{ transaction.user.name }}
                </div>
              </div>

              <div class="flex shrink-0 items-start gap-1.5 sm:gap-2">
                <div
                  class="flex min-w-0 max-w-[12.5rem] flex-col items-end gap-1 text-right leading-tight sm:max-w-[22rem]"
                  :class="
                    transaction.type === 'income'
                      ? transaction.is_debt_payment
                        ? 'text-sky-400'
                        : 'text-green-400'
                      : 'text-red-400'
                  "
                >
                  <template v-if="isSplitListRow(transaction)">
                    <span class="text-[11px] sm:text-base font-bold tabular-nums">
                      {{ transaction.type === 'income' ? '+' : '-' }}{{ formatCurrency(splitListPrimaryAmount(transaction)) }}
                    </span>
                    <button
                      type="button"
                      class="w-full max-w-full rounded-md border border-purple-500/40 bg-purple-900/50 px-2 py-1.5 text-left flex items-center transition hover:bg-purple-900/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500/60 sm:max-w-[22rem] sm:w-auto"
                      title="View how this payment was split"
                      @click.stop="openSplitDetailModal(transaction)"
                    >
                      <span class="text-[9px] sm:text-xs text-purple-200 leading-tight flex flex-wrap items-center gap-x-1 w-full min-w-0">
                        <span class="shrink-0 font-medium text-purple-100">Split:</span>
                        <span class="shrink-0">Total {{ formatCurrency(Number(transaction.amount) || 0) }}</span>
                        <span class="shrink-0 text-purple-300/90">by</span>
                        <span class="min-w-0 truncate font-medium">{{ transactionPayerDisplayLabel(transaction) }}</span>
                      </span>
                    </button>
                  </template>
                  <span v-else class="text-[11px] sm:text-base font-bold tabular-nums">
                    {{ transaction.type === 'income' ? '+' : '-' }}{{ formatCurrency(Number(transaction.amount) || 0) }}
                  </span>
                </div>

                <div class="flex shrink-0 flex-col items-end gap-1 pt-0.5 sm:flex-row sm:items-start sm:gap-1 sm:pt-0">
                  <!-- Lock Icon (if month is closed) -->
                  <svg
                    v-if="isCurrentMonthHardClosed"
                    class="h-4 w-4 shrink-0 text-amber-400"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                    title="Applied to funds - month closed"
                  >
                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                  </svg>

                  <!-- Action Buttons -->
                  <div v-if="!confirmDelete[transaction.id]" class="flex gap-1">
                    <button
                      @click.stop="confirmDelete[transaction.id] = true"
                      :disabled="isCurrentMonthHardClosed || transaction.is_closeout_initiated"
                      :class="['p-1 sm:p-2 rounded-md sm:rounded-lg transition-colors', (isCurrentMonthHardClosed || transaction.is_closeout_initiated) ? 'text-gray-500 cursor-not-allowed' : 'text-gray-400 hover:text-red-400 hover:bg-gray-700']"
                      :title="isCurrentMonthHardClosed ? 'Cannot edit locked transactions' : (transaction.is_closeout_initiated ? 'Closeout-generated entries cannot be deleted here' : 'Delete')"
                    >
                      <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>

                  <!-- Delete Confirmation -->
                  <div v-else class="flex gap-1">
                    <button
                      @click.stop="handleDeleteConfirm(transaction.id)"
                      class="px-2 py-0.5 text-[10px] sm:text-xs bg-red-600 hover:bg-red-700 text-white rounded transition-colors"
                    >
                      Yes
                    </button>
                    <button
                      @click.stop="confirmDelete[transaction.id] = false"
                      class="px-2 py-0.5 text-[10px] sm:text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 rounded transition-colors"
                    >
                      No
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Transaction Modal (on this page only) -->
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
        <div
          class="absolute inset-0 bg-black/50"
          @click="showForm = false"
        />
        <!-- Modal -->
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[90vh] overflow-y-auto">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">{{ editingTransactionId ? 'Edit Transaction' : 'New Transaction' }}</h2>
            <button
              @click="handleFormClose"
              class="text-gray-400 hover:text-white"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="p-4">
            <TransactionForm
              v-if="showForm"
              :categories="categories"
              :family-users="familyUsers"
              :funds="funds"
              :debts-payload="debtsPayload"
              :transaction="editingTransactionId ? getTransactionById(editingTransactionId) : null"
              @created="handleTransactionCreated"
              @updated="handleTransactionUpdated"
              @close="handleFormClose"
            />
          </div>
        </div>
      </div>
    </Transition>

    <Teleport to="body">
      <div
        v-if="splitDetailModalTransaction"
        class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center p-0 sm:p-4"
      >
        <div
          class="absolute inset-0 bg-black/60"
          @click="closeSplitDetailModal"
        />
        <div
          class="relative flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-t-2xl border border-gray-700 bg-gray-900 shadow-xl sm:rounded-2xl"
          role="dialog"
          aria-modal="true"
          aria-labelledby="split-detail-title"
        >
          <div class="flex shrink-0 items-center justify-between border-b border-gray-800 px-4 py-3">
            <h2 id="split-detail-title" class="text-lg font-semibold text-white">
              Split breakdown
            </h2>
            <button
              type="button"
              class="rounded p-1 text-gray-400 hover:bg-gray-800 hover:text-white"
              @click="closeSplitDetailModal"
            >
              <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="min-h-0 space-y-2 overflow-y-auto p-4">
            <p
              v-if="splitDetailModalTransaction.is_debt_payment || splitDetailModalTransaction.category?.name || splitDetailModalTransaction.description"
              class="text-sm text-gray-400"
            >
              <span v-if="splitDetailModalTransaction.is_debt_payment || splitDetailModalTransaction.category?.name">{{ getTransactionCategoryLabel(splitDetailModalTransaction) }}</span>
              <span
                v-if="splitDetailModalTransaction.description && !splitDetailModalTransaction.is_debt_payment"
              > · {{ splitDetailModalTransaction.description }}</span>
            </p>
            <div
              v-for="split in splitsSortedForModal(splitDetailModalTransaction)"
              :key="split.id"
              class="flex items-center justify-between gap-3 rounded-lg border border-gray-700 bg-gray-800/80 px-3 py-2.5"
            >
              <div class="min-w-0">
                <span class="font-medium text-gray-200">{{ split.user?.name || 'Member' }}</span>
                <span
                  v-if="isSplitRowForCurrentUser(split)"
                  class="ml-1.5 text-xs font-medium text-purple-400"
                >
                  (You)
                </span>
              </div>
              <div class="shrink-0 text-right tabular-nums">
                <p class="font-semibold text-white">
                  {{ formatCurrency(Number(split.amount) || 0) }}
                </p>
                <p class="text-xs text-gray-400">
                  {{ formatSplitSharePercent(split.share_percentage) }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useApi } from '../composables/useApi';
import TransactionForm from '../components/TransactionForm.vue';
import { debtPaymentCategoryLine } from '../support/debtPaymentLabel.js';

const router = useRouter();
const route = useRoute();
const { get, put, del, post, loading, error } = useApi();

const transactions = ref([]);
const categories = ref([]);
const familyUsers = ref([]);
const funds = ref([]);
const debtsPayload = ref({ owed: [], owing: [], family_debts: [] });
const showForm = ref(false);
const editingTransactionId = ref(null);
const confirmDelete = ref({});
const selectedMonthFilter = ref('');
const customStartDate = ref('');
const customEndDate = ref('');
const closeoutStatus = ref(null);
const closedMonths = ref([]);
const currentUser = ref(null);
const splitDetailModalTransaction = ref(null);

function navigateToMonthSummary() {
  if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
    router.push(`/month-summary/${selectedMonthFilter.value}`);
  }
}

function handleTransactionCreatedFromFab(event) {
  void reloadCurrentFilterData();
}

const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

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

function getDefaultMonthValue() {
  const today = new Date();
  const year = today.getFullYear();
  const month = String(today.getMonth() + 1).padStart(2, '0');
  return `${year}-${month}`;
}

function parseMonthQueryValue(value) {
  const normalized = Array.isArray(value) ? value[0] : value;
  if (typeof normalized !== 'string') {
    return null;
  }
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(normalized)) {
    return null;
  }
  return normalized;
}

function syncMonthQuery(monthValue) {
  const normalizedMonth = parseMonthQueryValue(monthValue);
  const currentMonthQuery = parseMonthQueryValue(route.query.month);

  if (normalizedMonth === currentMonthQuery) {
    return;
  }

  const nextQuery = { ...route.query };
  if (normalizedMonth) {
    nextQuery.month = normalizedMonth;
  } else {
    delete nextQuery.month;
  }

  router.replace({ query: nextQuery });
}

function applyMonthSelection(monthValue) {
  selectedMonthFilter.value = monthValue;
  const [startDate, endDate] = getMonthDateRange(monthValue);
  fetchData(startDate, endDate);
}

function initializeMonthFilterFromQuery() {
  const monthFromQuery = parseMonthQueryValue(route.query.month);
  const resolvedMonth = monthFromQuery || getDefaultMonthValue();
  applyMonthSelection(resolvedMonth);

  if (!monthFromQuery) {
    syncMonthQuery(resolvedMonth);
  }
}

onMounted(async () => {
  await fetchCurrentUser();
  initializeMonthFilterFromQuery();
  fetchClosedMonths();
  window.addEventListener('transaction-created', handleTransactionCreatedFromFab);
});

watch(
  () => route.query.month,
  (monthQueryValue) => {
    const monthFromQuery = parseMonthQueryValue(monthQueryValue);
    if (!monthFromQuery) {
      const defaultMonth = getDefaultMonthValue();
      if (defaultMonth !== selectedMonthFilter.value) {
        applyMonthSelection(defaultMonth);
      }
      syncMonthQuery(defaultMonth);
      return;
    }
    if (monthFromQuery === selectedMonthFilter.value) {
      return;
    }
    applyMonthSelection(monthFromQuery);
  }
);

async function fetchCurrentUser() {
  try {
    currentUser.value = await get('/user');
  } catch (err) {
    console.error('Failed to fetch current user:', err);
  }
}

function getMonthDateRange(yearMonth) {
  const [year, month] = yearMonth.split('-').map(Number);
  const startDate = `${yearMonth}-01`;
  const lastDay = new Date(year, month, 0).getDate();
  const endDate = `${yearMonth}-${String(lastDay).padStart(2, '0')}`;
  return [startDate, endDate];
}

async function fetchData(startDate = null, endDate = null) {
  try {
    const params = new URLSearchParams();
    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    const query = params.toString() ? `?${params.toString()}` : '';

    const [txData, catData, usersData, fundsData, debtsData] = await Promise.all([
      get(`/transactions${query}`),
      get('/categories'),
      get('/family/users'),
      get('/funds'),
      get('/debts'),
    ]);
    transactions.value = txData;
    categories.value = catData;
    familyUsers.value = usersData;
    funds.value = fundsData;
    debtsPayload.value =
      debtsData && typeof debtsData === 'object' ? debtsData : debtsPayload.value;

    // Fetch closeout status for current month
    if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
      const [year, month] = selectedMonthFilter.value.split('-').map(Number);
      try {
        const status = await post('/closeout/status', { year, month });
        closeoutStatus.value = status;
      } catch (err) {
        console.error('Failed to fetch closeout status:', err);
      }
    }
  } catch (err) {
    console.error('Failed to fetch data:', err);
  }
}

async function fetchClosedMonths() {
  try {
    const months = await get('/closeout/closed-months');
    closedMonths.value = months;
  } catch (err) {
    console.error('Failed to fetch closed months:', err);
  }
}

function handleMonthFilterChange() {
  if (selectedMonthFilter.value === 'custom') {
    const today = new Date();
    customStartDate.value = today.toISOString().split('T')[0];
    customEndDate.value = today.toISOString().split('T')[0];
    syncMonthQuery(null);
  } else {
    syncMonthQuery(selectedMonthFilter.value);
    const [startDate, endDate] = getMonthDateRange(selectedMonthFilter.value);
    fetchData(startDate, endDate);
  }
}

function applyCustomRange() {
  if (customStartDate.value && customEndDate.value) {
    fetchData(customStartDate.value, customEndDate.value);
  }
}

function isSplitListRow(transaction) {
  return Boolean(transaction?.splits?.length);
}

function currentUserSplitAmount(transaction) {
  const uid = currentUser.value?.id;
  if (uid == null || !transaction?.splits?.length) {
    return null;
  }
  const row = transaction.splits.find(s => Number(s.user_id) === Number(uid));
  if (!row) {
    return null;
  }
  return Number(row.amount) || 0;
}

function expenseAmountForViewerTotals(transaction) {
  if (transaction.type !== 'expense') {
    return Number(transaction.amount) || 0;
  }
  if (isSplitListRow(transaction)) {
    const mine = currentUserSplitAmount(transaction);
    if (mine != null) {
      return mine;
    }
  }
  return Number(transaction.amount) || 0;
}

const transactionsByDay = computed(() => {
  const grouped = {};

  transactions.value.forEach(tx => {
    const date = tx.transaction_date;
    if (!grouped[date]) {
      grouped[date] = {
        date,
        transactions: [],
        totalIncome: 0,
        totalExpenses: 0,
      };
    }
    grouped[date].transactions.push(tx);
    if (tx.type === 'income') {
      if (!tx.is_debt_payment) {
        grouped[date].totalIncome += Number(tx.amount) || 0;
      }
    } else {
      grouped[date].totalExpenses += expenseAmountForViewerTotals(tx);
    }
  });

  return Object.values(grouped).sort((a, b) => parseDateStringAsLocal(b.date) - parseDateStringAsLocal(a.date));
});

const totalIncome = computed(() => {
  return transactions.value
    .filter(tx => tx.type === 'income' && !tx.is_debt_payment)
    .reduce((sum, tx) => sum + (Number(tx.amount) || 0), 0);
});

const totalExpenses = computed(() => {
  return transactions.value
    .filter(tx => tx.type === 'expense')
    .reduce((sum, tx) => sum + expenseAmountForViewerTotals(tx), 0);
});

const isCurrentMonthHardClosed = computed(() => {
  return closeoutStatus.value?.hard_close != null;
});

function isMonthClosed(year, month) {
  return closedMonths.value.some(m => m.year === year && m.month === month);
}

const currentMonthYear = computed(() => {
  if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
    const [year, month] = selectedMonthFilter.value.split('-').map(Number);
    return { year, month };
  }
  return null;
});

const isUserSoftClosed = computed(() => {
  if (!closeoutStatus.value) return false;
  const myClose = closeoutStatus.value.soft_closes?.find(sc => sc.user_id === currentUser.value?.id);
  return !!myClose;
});

/** True when the loaded list has at least one row (filter is a single calendar month, so rows are for that month). */
const selectedMonthHasTransactions = computed(() => transactions.value.length > 0);

const showCloseOutHeaderButton = computed(() => {
  if (!currentMonthYear.value || isCurrentMonthHardClosed.value) {
    return false;
  }
  if (isUserSoftClosed.value) {
    return true;
  }

  return selectedMonthHasTransactions.value;
});

const monthLockUi = computed(() => {
  if (isCurrentMonthHardClosed.value) {
    return {
      borderClass: 'border-amber-600/50',
      title: 'Month is hard-closed — transactions are locked.',
    };
  }
  if (isUserSoftClosed.value) {
    return {
      borderClass: 'border-blue-600/40',
      title: 'You have closed out this month. Use Undo in the header to reopen it for yourself.',
    };
  }
  return {
    borderClass: 'border-gray-700',
    title: 'This month is open for you — use Close Out in the header when you are done.',
  };
});

function formatDate(dateStr) {
  return parseDateStringAsLocal(dateStr).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: parseDateStringAsLocal(dateStr).getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined,
  });
}

function parseDateStringAsLocal(dateStr) {
  const [year, month, day] = String(dateStr).split('T')[0].split('-').map(Number);
  return new Date(year, month - 1, day);
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
}

function splitListPrimaryAmount(transaction) {
  const mine = currentUserSplitAmount(transaction);
  if (mine != null) {
    return mine;
  }
  return Number(transaction.amount) || 0;
}

function transactionPayerDisplayLabel(transaction) {
  const uid = currentUser.value?.id;
  if (uid != null && Number(transaction.user_id) === Number(uid)) {
    return 'You';
  }
  return transaction.user?.name || 'Unknown';
}

/**
 * Small attribute pills on each row (debt repayment, advance, split, borrow, closeout).
 * @param {object} tx
 * @returns {{ key: string, label: string, classes: string, title?: string }[]}
 */
function transactionKindPills(tx) {
  if (!tx) {
    return [];
  }

  /** @type {{ key: string, label: string, classes: string, title?: string }[]} */
  const pills = [];

  if (tx.type === 'income' && tx.is_borrow) {
    pills.push({
      key: 'borrow',
      label: 'Borrow',
      classes: 'bg-orange-900/55 text-orange-200',
      title: 'Income from borrowing against a personal or family fund',
    });
  }

  if (tx.type === 'expense' && tx.advance_fund_id) {
    const fundName = tx.advanceFund?.name?.trim();
    pills.push({
      key: 'advance',
      label: 'Advance',
      classes: 'bg-amber-900/55 text-amber-200',
      title: fundName ? `Advances against: ${fundName}` : 'Advances against a fund at closeout',
    });
  }

  if (tx.is_debt_payment && tx.type === 'expense') {
    pills.push({
      key: 'debt-payment',
      label: 'Debt payment',
      classes: 'bg-sky-900/55 text-sky-200',
    });
  }

  if (tx.is_debt_payment && tx.type === 'income') {
    pills.push({
      key: 'repayment',
      label: 'Repayment',
      classes: 'bg-sky-900/55 text-sky-200',
    });
  }

  if (tx.type === 'expense' && tx.is_split) {
    pills.push({
      key: 'split',
      label: 'Split',
      classes: 'bg-purple-900/55 text-purple-200',
      title: 'Split between family members',
    });
  }

  if (tx.is_closeout_initiated) {
    pills.push({
      key: 'closeout',
      label: 'Closeout',
      classes: 'bg-purple-900/60 text-purple-200',
      title: 'Generated by closeout or title completion',
    });
  }

  return pills;
}

function getTransactionCategoryLabel(transaction) {
  if (transaction.is_debt_payment) {
    return debtPaymentCategoryLine(transaction);
  }
  return transaction.category?.name || 'Uncategorized';
}

function openSplitDetailModal(transaction) {
  if (!transaction?.splits?.length) {
    return;
  }
  splitDetailModalTransaction.value = transaction;
}

function closeSplitDetailModal() {
  splitDetailModalTransaction.value = null;
}

function splitsSortedForModal(transaction) {
  if (!transaction?.splits?.length) {
    return [];
  }
  const uid = currentUser.value?.id;
  return [...transaction.splits].sort((a, b) => {
    const aMine = uid != null && Number(a.user_id) === Number(uid);
    const bMine = uid != null && Number(b.user_id) === Number(uid);
    if (aMine !== bMine) {
      return aMine ? -1 : 1;
    }
    const nameA = (a.user?.name || `User ${a.user_id}`).toLowerCase();
    const nameB = (b.user?.name || `User ${b.user_id}`).toLowerCase();
    return nameA.localeCompare(nameB);
  });
}

function isSplitRowForCurrentUser(split) {
  const uid = currentUser.value?.id;
  if (uid == null) {
    return false;
  }
  return Number(split.user_id) === Number(uid);
}

function formatSplitSharePercent(value) {
  const n = Number(value);
  if (Number.isNaN(n)) {
    return '—';
  }
  const rounded = Math.round(n * 100) / 100;
  if (Math.abs(rounded - Math.round(rounded)) < 0.001) {
    return `${Math.round(rounded)}%`;
  }
  return `${rounded.toFixed(2)}%`;
}

function getTransactionById(id) {
  return transactions.value.find(t => t.id === id);
}

function isSystemCloseoutEntry(transaction) {
  if (transaction?.is_closeout_initiated) {
    return true;
  }

  return Boolean(transaction?.is_debt_payment && transaction?.type === 'income');
}

async function handleTransactionCreated(transaction) {
  await reloadCurrentFilterData();
  showForm.value = false;
}

async function handleTransactionUpdated(transaction) {
  const index = transactions.value.findIndex(t => t.id === transaction.id);
  if (index !== -1) {
    transactions.value[index] = transaction;
  }
  showForm.value = false;
  editingTransactionId.value = null;
}

async function reloadCurrentFilterData() {
  if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
    const [startDate, endDate] = getMonthDateRange(selectedMonthFilter.value);
    await fetchData(startDate, endDate);
    return;
  }

  if (customStartDate.value && customEndDate.value) {
    await fetchData(customStartDate.value, customEndDate.value);
    return;
  }

  await fetchData();
}

async function handleDeleteConfirm(transactionId) {
  try {
    await del(`/transactions/${transactionId}`);
    confirmDelete.value[transactionId] = false;
    if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
      const [sd, ed] = getMonthDateRange(selectedMonthFilter.value);
      await fetchData(sd, ed);
    } else if (customStartDate.value && customEndDate.value) {
      await fetchData(customStartDate.value, customEndDate.value);
    } else {
      await fetchData();
    }
  } catch (err) {
    console.error('Failed to delete transaction:', err);
  }
}

function handleFormClose() {
  showForm.value = false;
  editingTransactionId.value = null;
}

function openEditForm(transactionId) {
  const tx = getTransactionById(transactionId);
  if (isSystemCloseoutEntry(tx)) {
    return;
  }
  editingTransactionId.value = transactionId;
  showForm.value = true;
}

async function handleSoftClose() {
  try {
    await post('/closeout/soft-close', currentMonthYear.value);
    if (currentMonthYear.value) {
      const status = await post('/closeout/status', currentMonthYear.value);
      closeoutStatus.value = status;
    }
  } catch (err) {
    console.error('Failed to soft close month:', err);
  }
}

async function handleUndoSoftClose() {
  try {
    await post('/closeout/undo-soft-close', currentMonthYear.value);
    if (currentMonthYear.value) {
      const status = await post('/closeout/status', currentMonthYear.value);
      closeoutStatus.value = status;
    }
  } catch (err) {
    console.error('Failed to undo soft close:', err);
  }
}

</script>
