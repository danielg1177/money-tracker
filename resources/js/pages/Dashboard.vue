<template>
  <div class="pb-32">
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10">
      <h1 class="text-xl font-bold text-white">Dashboard</h1>
      <p class="text-gray-400 text-sm mt-1">
        {{ greeting }}, {{ user?.name || 'there' }}
      </p>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading overview…</p>
      </div>
    </div>

    <div v-else-if="error" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ error }}</p>
      <button type="button" class="mt-2 text-xs text-red-400 hover:text-red-300 underline" @click="load">
        Try again
      </button>
    </div>

    <div v-else class="px-4 py-4 space-y-4">
      <div v-if="!user?.family_id" class="p-4 bg-amber-900/20 border border-amber-700/50 rounded-xl">
        <p class="text-amber-200 text-sm">
          You are not assigned to a family yet. Ask an admin to link your account, or use the admin tools if you have access.
        </p>
      </div>

      <!-- Bank Account Balance -->
      <div v-if="user?.family_id">
        <!-- Feature not enabled: show enable prompt -->
        <div
          v-if="!bankBalance || !bankBalance.enabled"
          class="bg-gray-800 border border-gray-700 rounded-xl p-4 flex items-center justify-between"
        >
          <div>
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wide">Bank Account</p>
            <p class="text-gray-500 text-sm mt-1">Track your account balance in real time</p>
          </div>
          <button
            type="button"
            class="shrink-0 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition-colors"
            @click="enableBankBalance"
          >
            Enable
          </button>
        </div>
        <!-- Feature enabled: show balance card -->
        <div v-else class="bg-gray-800 border border-gray-700 rounded-xl p-4">
          <div class="flex items-start justify-between gap-2 mb-3">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wide">Bank Account</p>
            <div class="flex items-center gap-2">
              <button
                v-if="!bankBalanceEdit"
                type="button"
                class="text-xs text-blue-400 hover:text-blue-300 transition-colors"
                @click="startEditBankBalance"
              >
                Update
              </button>
              <button
                type="button"
                class="text-xs text-gray-600 hover:text-gray-400 transition-colors"
                @click="disableBankBalance"
                title="Disable bank balance tracking"
              >
                Off
              </button>
            </div>
          </div>
          <!-- Balance display (not editing) -->
          <div v-if="!bankBalanceEdit">
            <p
              v-if="bankBalance.computed_balance !== null"
              class="text-3xl font-bold"
              :class="bankBalance.computed_balance >= 0 ? 'text-white' : 'text-red-400'"
            >
              {{ formatCurrency(bankBalance.computed_balance) }}
            </p>
            <p v-else class="text-gray-400 text-sm">
              Set your current balance to start tracking.
              <button type="button" class="text-blue-400 underline ml-1" @click="startEditBankBalance">Set now</button>
            </p>
            <p v-if="bankBalance.bank_balance_set_at" class="text-gray-500 text-xs mt-1">
              Since {{ bankBalance.bank_balance_set_at }}
              <span v-if="bankBalance.delta" class="ml-2 text-gray-600">
                ({{ bankBalance.delta.income >= 0 ? '+' : '' }}{{ formatCurrency(bankBalance.delta.income - bankBalance.delta.expense - bankBalance.delta.title_savings_completed) }} since set)
              </span>
            </p>
          </div>
          <!-- Edit mode -->
          <div v-else class="space-y-3">
            <div>
              <label class="block text-xs text-gray-400 mb-1">Current account balance</label>
              <div class="flex items-center gap-2">
                <span class="text-gray-400 text-sm">$</span>
                <input
                  v-model="bankBalanceInput"
                  v-bind="mobileDecimalNumberAttrs"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  class="flex-1 bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500"
                  @keydown.enter="saveBankBalance"
                  @keydown.escape="cancelBankBalanceEdit"
                />
              </div>
              <p class="text-xs text-gray-500 mt-1">Enter your bank's current balance. Future transactions will be tracked from today.</p>
            </div>
            <div class="flex gap-2">
              <button
                type="button"
                class="flex-1 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50"
                :disabled="bankBalanceSaving"
                @click="saveBankBalance"
              >
                {{ bankBalanceSaving ? 'Saving...' : 'Save Balance' }}
              </button>
              <button
                type="button"
                class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-gray-300 text-sm rounded-lg transition-colors"
                @click="cancelBankBalanceEdit"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Family close progress: earliest calendar month that has transactions and is not hard-closed -->
      <div v-else-if="user?.family_id && closeoutYearMonth">
        <div class="px-1 mb-3">
          <h2 class="text-sm font-semibold text-gray-400 uppercase">Family close progress</h2>
          <p class="text-xs text-gray-500 mt-0.5">{{ closeoutMonthLabel }}</p>
        </div>
        <div class="bg-gray-800 border border-gray-700 rounded-xl p-4 space-y-4">
          <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Family members</p>
            <div class="space-y-2">
              <div
                v-for="member in familyUsers"
                :key="member.id"
                class="flex items-center justify-between gap-2 text-sm text-gray-200"
              >
                <span class="min-w-0 truncate">{{ member.name }}</span>
                <div
                  class="shrink-0 flex w-9 items-center justify-center"
                  :title="memberLockTitle(member.id)"
                >
                  <svg
                    v-if="isTargetMonthHardClosed"
                    class="h-4 w-4 text-amber-400"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                    aria-hidden="true"
                  >
                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                  </svg>
                  <svg
                    v-else-if="memberHasSoftClosed(member.id)"
                    class="h-4 w-4 text-blue-400"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                    aria-hidden="true"
                  >
                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                  </svg>
                  <svg
                    v-else
                    class="h-4 w-4 text-gray-400"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                  </svg>
                </div>
              </div>
            </div>
          </div>

          <div
            v-if="user?.can_manage_family && allUsersSoftClosed && !isTargetMonthHardClosed"
            class="pt-3 border-t border-gray-700"
          >
            <button
              type="button"
              class="w-full px-3 py-2.5 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg transition-colors"
              @click="handleHardClose"
            >
              Hard close month
            </button>
          </div>
        </div>
        <p class="text-gray-500 text-xs mt-2 px-1">
          Soft-close status for {{ closeoutMonthLabel }}. When everyone has marked this month closed out, a family manager can hard close to apply rules and confirm split debts.
        </p>
      </div>

      <!-- Split Debt Summary Section -->
      <div v-if="splitDebtSummary.length > 0">
        <h2 class="text-sm font-semibold text-gray-400 uppercase px-1 mb-3">This Month's Split Expenses</h2>
        <div class="space-y-3">
          <div
            v-for="item in splitDebtSummary"
            :key="item.counterpart.id"
            class="bg-gray-800 border border-gray-700 rounded-xl p-4 flex items-center justify-between"
          >
            <div class="flex-1">
              <p class="text-white font-medium">{{ item.counterpart.name }}</p>
              <p :class="getNetAmountClass(item)">
                {{ getNetAmountText(item) }}
              </p>
            </div>
            <button
              @click="openDetailsModal(item)"
              class="text-blue-400 text-sm underline hover:text-blue-300 transition-colors flex-shrink-0"
            >
              View Details
            </button>
          </div>
        </div>
        <p class="text-gray-500 text-xs mt-2 px-1">
          These splits will be applied to your debt balance when your family closes the month.
          In View Details, debt payment rows show the same “Debt Payment: …” line as Transactions (paid debt name from the linked debt).
        </p>
      </div>

      <!-- This Month's Income & Expenses -->
      <div v-if="user?.family_id">
        <h2 class="text-sm font-semibold text-gray-400 uppercase px-1 mb-3">This Month</h2>
        <div class="grid grid-cols-2 gap-3">
          <div class="bg-gray-800 border border-gray-700 rounded-xl p-4">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wide">Income</p>
            <p class="text-xl font-bold text-green-400 mt-1">{{ formatCurrency(monthlyTotals.total_income) }}</p>
          </div>
          <div class="bg-gray-800 border border-gray-700 rounded-xl p-4">
            <p class="text-gray-400 text-xs font-semibold uppercase tracking-wide">Expenses</p>
            <p class="text-xl font-bold text-red-400 mt-1">{{ formatCurrency(monthlyTotals.total_expenses) }}</p>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <router-link
          to="/transactions"
          class="block bg-gray-800 border border-gray-700 rounded-xl p-3 hover:border-gray-600 transition-colors"
        >
          <p class="text-gray-400 text-xs font-semibold uppercase tracking-wide">Transactions</p>
          <p class="text-2xl font-bold text-white mt-1">{{ transactionCountThisMonth }}</p>
          <p class="text-gray-500 text-xs mt-1">This month · View all</p>
        </router-link>

        <router-link
          to="/funds"
          class="block bg-gray-800 border border-gray-700 rounded-xl p-3 hover:border-gray-600 transition-colors"
        >
          <p class="text-gray-400 text-xs font-semibold uppercase tracking-wide">Funds</p>
          <p class="text-2xl font-bold text-blue-400 mt-1">{{ fundCount }}</p>
          <p class="text-gray-500 text-xs mt-1">Manage funds</p>
        </router-link>

        <router-link
          to="/debts"
          class="block bg-gray-800 border border-gray-700 rounded-xl p-3 hover:border-gray-600 transition-colors"
        >
          <p class="text-gray-400 text-xs font-semibold uppercase tracking-wide">Debts</p>
          <p class="text-2xl font-bold text-purple-400 mt-1">{{ debtCount }}</p>
          <p class="text-gray-500 text-xs mt-1">Owed & owing</p>
        </router-link>
      </div>
    </div>

    <!-- Details Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="selectedSplitItem" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="selectedSplitItem = null" />
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">Splits with {{ selectedSplitItem.counterpart.name }}</h2>
            <button @click="selectedSplitItem = null" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-3">
            <div
              v-for="txn in selectedSplitItem.transactions"
              :key="txn.debt_id"
              class="bg-gray-800 border border-gray-700 rounded-lg p-3"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                  <p class="text-gray-300 text-sm">
                    {{ formatDate(txn.transaction?.transaction_date) }}
                  </p>
                  <div class="mt-1 flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span class="text-sm font-medium text-white">
                      {{ splitTransactionCategoryLabel(txn) }}
                    </span>
                    <span
                      v-if="splitTransactionDescription(txn) && !txn.transaction?.is_debt_payment"
                      class="text-xs text-gray-400"
                      :title="splitTransactionDescription(txn)"
                    >
                      {{ splitTransactionDescription(txn) }}
                    </span>
                  </div>
                </div>
                <div class="text-right flex-shrink-0">
                  <p :class="getTransactionAmountClass(txn)">
                    {{ formatCurrency(txn.amount) }}
                  </p>
                  <p class="text-gray-400 text-xs mt-1">
                    {{ txn.direction === 'you_owe' ? 'You owe' : 'They owe' }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useAuth } from '../composables/useAuth';
import { useApi } from '../composables/useApi';
import { debtPaymentCategoryLine } from '../support/debtPaymentLabel.js';
import { mobileDecimalNumberAttrs } from '../support/mobileNumericInputAttrs.js';

const { user } = useAuth();
const { get, post, put, loading, error } = useApi();

const transactions = ref([]);
const funds = ref([]);
const debtsPayload = ref({ owed: [], owing: [] });
const splitDebtSummary = ref([]);
const selectedSplitItem = ref(null);
const familyUsers = ref([]);
const closeoutStatus = ref(null);
const monthlyTotals = ref({ total_income: 0, total_expenses: 0 });
const bankBalance = ref(null);
const bankBalanceEdit = ref(false);
const bankBalanceInput = ref('');
const bankBalanceSaving = ref(false);
/** Earliest year/month that has transactions and is not hard-closed; drives closeout UI. */
const closeoutYearMonth = ref(null);
const closedMonths = ref([]);

const monthNames = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

const greeting = computed(() => {
  const h = new Date().getHours();
  if (h < 12) {
    return 'Good morning';
  }
  if (h < 18) {
    return 'Good afternoon';
  }
  return 'Good evening';
});

/**
 * @param {{ transaction_date?: string }} tx
 */
function isTransactionInCalendarMonth(tx, year, month) {
  const raw = tx?.transaction_date;
  if (!raw) {
    return false;
  }
  const part = String(raw).split('T')[0];
  const [ys, ms] = part.split('-');
  const y = Number(ys);
  const m = Number(ms);

  return y === year && m === month;
}

const transactionCountThisMonth = computed(() => {
  const d = new Date();

  return transactions.value.filter(tx =>
    isTransactionInCalendarMonth(tx, d.getFullYear(), d.getMonth() + 1),
  ).length;
});
const fundCount = computed(() => funds.value.length);
const debtCount = computed(() => {
  const d = debtsPayload.value;
  return (d.owed?.length || 0) + (d.owing?.length || 0) + (d.family_debts?.length || 0);
});

const isTargetMonthHardClosed = computed(() => closeoutStatus.value?.hard_close != null);

const allUsersSoftClosed = computed(() => closeoutStatus.value?.all_soft_closed ?? false);

const closeoutMonthLabel = computed(() => {
  const ym = closeoutYearMonth.value;
  if (!ym?.year || !ym?.month) {
    return '';
  }

  return `${monthNames[ym.month - 1]} ${ym.year}`;
});

function memberHasSoftClosed(memberId) {
  return Boolean(
    closeoutStatus.value?.soft_closes?.some(sc => Number(sc.user_id) === Number(memberId)),
  );
}

function memberLockTitle(memberId) {
  if (isTargetMonthHardClosed.value) {
    return 'Month is hard-closed — transactions are locked.';
  }
  if (memberHasSoftClosed(memberId)) {
    return 'Marked this month closed out.';
  }

  return 'Has not marked this month closed out yet.';
}

/**
 * @param {Array<{ transaction_date?: string }>} transactions
 * @param {Array<{ year: number, month: number }>} hardClosedList
 * @returns {{ year: number, month: number } | null}
 */
function pickFirstOpenTransactionMonth(transactions, hardClosedList) {
  const byKey = new Map();
  for (const tx of transactions) {
    const raw = tx.transaction_date;
    if (!raw) {
      continue;
    }
    const part = String(raw).split('T')[0];
    const [ys, ms] = part.split('-');
    const year = Number(ys);
    const month = Number(ms);
    if (!year || !month) {
      continue;
    }
    const key = year * 100 + month;
    if (!byKey.has(key)) {
      byKey.set(key, { year, month });
    }
  }
  const yms = [...byKey.values()].sort((a, b) => a.year - b.year || a.month - b.month);
  for (const ym of yms) {
    const hard = hardClosedList.some(
      m => Number(m.year) === ym.year && Number(m.month) === ym.month,
    );
    if (!hard) {
      return ym;
    }
  }

  return null;
}

onMounted(() => {
  load();
});

async function load() {
  try {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth() + 1;

    const [tx, fd, db, splits, closed, totals, bal] = await Promise.all([
      get('/transactions'),
      get('/funds'),
      get('/debts'),
      get(`/split-debt-summary?year=${year}&month=${month}`),
      get('/closeout/closed-months'),
      get('/dashboard/monthly-totals'),
      get('/bank-balance'),
    ]);
    transactions.value = Array.isArray(tx) ? tx : [];
    funds.value = Array.isArray(fd) ? fd : [];
    debtsPayload.value = db && typeof db === 'object' ? db : { owed: [], owing: [] };
    splitDebtSummary.value = Array.isArray(splits) ? splits : [];
    closedMonths.value = Array.isArray(closed) ? closed : [];
    monthlyTotals.value = totals && typeof totals === 'object' ? totals : { total_income: 0, total_expenses: 0 };
    bankBalance.value = bal && typeof bal === 'object' ? bal : null;

    const closeoutTarget = pickFirstOpenTransactionMonth(transactions.value, closedMonths.value);

    if (!user.value?.family_id) {
      closeoutYearMonth.value = null;
      familyUsers.value = [];
      closeoutStatus.value = null;
    } else if (closeoutTarget) {
      closeoutYearMonth.value = closeoutTarget;
      try {
        const [usersData, status] = await Promise.all([
          get('/family/users'),
          post('/closeout/status', closeoutTarget),
        ]);
        familyUsers.value = Array.isArray(usersData) ? usersData : [];
        closeoutStatus.value = status;
      } catch (err) {
        console.error('Dashboard closeout / family users load failed:', err);
        familyUsers.value = [];
        closeoutStatus.value = null;
      }
    } else {
      closeoutYearMonth.value = null;
      familyUsers.value = [];
      closeoutStatus.value = null;
    }
  } catch (err) {
    console.error('Dashboard load failed:', err);
  }
}

async function handleHardClose() {
  if (!closeoutYearMonth.value) {
    return;
  }
  if (!confirm('Are you sure? This will apply all closeout rules and cannot be undone.')) {
    return;
  }
  try {
    await post('/closeout/hard-close', closeoutYearMonth.value);
    const status = await post('/closeout/status', closeoutYearMonth.value);
    closeoutStatus.value = status;
    await load();
  } catch (err) {
    console.error('Failed to hard close month:', err);
  }
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
}

function formatDate(dateString) {
  if (!dateString) return '';
  const date = new Date(dateString);
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(date);
}

async function saveBankBalance() {
  if (bankBalanceSaving.value) return;
  const amount = parseFloat(bankBalanceInput.value);
  if (isNaN(amount) || amount < 0) return;
  bankBalanceSaving.value = true;
  try {
    const result = await put('/bank-balance', { bank_balance: amount });
    bankBalance.value = result;
    bankBalanceEdit.value = false;
  } catch (err) {
    console.error('Failed to save bank balance:', err);
  } finally {
    bankBalanceSaving.value = false;
  }
}

async function enableBankBalance() {
  try {
    const result = await put('/bank-balance', { bank_balance_enabled: true });
    bankBalance.value = result;
  } catch (err) {
    console.error('Failed to enable bank balance:', err);
  }
}

async function disableBankBalance() {
  if (!confirm('Disable bank balance tracking?')) return;
  try {
    const result = await put('/bank-balance', { bank_balance_enabled: false });
    bankBalance.value = result;
  } catch (err) {
    console.error('Failed to disable bank balance:', err);
  }
}

function startEditBankBalance() {
  const current = bankBalance.value?.computed_balance ?? bankBalance.value?.bank_balance ?? '';
  bankBalanceInput.value = current !== null && current !== '' ? String(current) : '';
  bankBalanceEdit.value = true;
}

function cancelBankBalanceEdit() {
  bankBalanceEdit.value = false;
  bankBalanceInput.value = '';
}

function getNetAmountText(item) {
  const net = (item.you_owe || 0) - (item.they_owe || 0);
  if (net > 0.01) {
    return `You owe ${formatCurrency(net)}`;
  }
  if (net < -0.01) {
    return `They owe you ${formatCurrency(-net)}`;
  }
  return 'Settled';
}

function getNetAmountClass(item) {
  const net = (item.you_owe || 0) - (item.they_owe || 0);
  if (net > 0.01) {
    return 'text-red-400 text-sm mt-1';
  }
  if (net < -0.01) {
    return 'text-green-400 text-sm mt-1';
  }
  return 'text-gray-400 text-sm mt-1';
}

function getTransactionAmountClass(txn) {
  return txn.direction === 'you_owe' ? 'text-red-400 font-medium' : 'text-green-400 font-medium';
}

function openDetailsModal(item) {
  selectedSplitItem.value = item;
}

function splitTransactionCategoryLabel(txn) {
  const t = txn.transaction;
  if (t?.is_debt_payment) {
    return debtPaymentCategoryLine(t);
  }
  return t?.category?.name ?? 'Uncategorized';
}

function splitTransactionDescription(txn) {
  const raw = txn.transaction?.description;
  if (typeof raw !== 'string') {
    return '';
  }
  const trimmed = raw.trim();

  return trimmed.length > 0 ? trimmed : '';
}
</script>
