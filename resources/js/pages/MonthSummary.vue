<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useApi } from '../composables/useApi';

const route = useRoute();
const router = useRouter();
const { get, post, loading, error } = useApi();

// Parse route params
const yearMonth = route.params.yearMonth; // "2026-05"
const [year, month] = yearMonth.split('-').map(Number);

const summary = ref(null);
const currentUser = ref(null);
const isClosing = ref(false);

// Month label
const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const monthLabel = computed(() => `${monthNames[month - 1]} ${year} Summary`);

// Load data on mount
onMounted(async () => {
  await fetchCurrentUser();
  const response = await get(`/month-summary?year=${year}&month=${month}`);
  if (response) {
    summary.value = response;
  }
});

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
  return !isHardClosed.value && allSoftClosed.value && currentUser.value?.is_admin;
});

// Closeout functions
async function handleSoftClose() {
  try {
    isClosing.value = true;
    await post('/closeout/soft-close', { year, month });
    // Refresh the data
    const response = await get(`/month-summary?year=${year}&month=${month}`);
    if (response) {
      summary.value = response;
    }
  } catch (err) {
    console.error('Failed to soft close:', err);
  } finally {
    isClosing.value = false;
  }
}

async function handleUndoSoftClose() {
  try {
    isClosing.value = true;
    await post('/closeout/undo-soft-close', { year, month });
    // Refresh the data
    const response = await get(`/month-summary?year=${year}&month=${month}`);
    if (response) {
      summary.value = response;
    }
  } catch (err) {
    console.error('Failed to undo soft close:', err);
  } finally {
    isClosing.value = false;
  }
}

async function handleHardClose() {
  try {
    isClosing.value = true;
    await post('/closeout/hard-close', { year, month });
    // Refresh the data
    const response = await get(`/month-summary?year=${year}&month=${month}`);
    if (response) {
      summary.value = response;
    }
  } catch (err) {
    console.error('Failed to hard close:', err);
  } finally {
    isClosing.value = false;
  }
}

// Separate expenses and income categories
const expenseCategories = computed(() => {
  return (summary.value?.category_totals || []).filter(cat => cat.type === 'expense');
});

const incomeCategories = computed(() => {
  return (summary.value?.category_totals || []).filter(cat => cat.type === 'income');
});
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
      <!-- Section 1a: Expenses -->
      <div class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">Expenses</h2>

        <div v-if="expenseCategories.length === 0" class="text-sm text-gray-500">
          No expenses this month
        </div>

        <div v-else class="space-y-2">
          <div
            v-for="cat in expenseCategories"
            :key="`${cat.type}-${cat.category_id}`"
            class="flex items-center justify-between px-3 py-2 bg-gray-800 rounded-lg border border-gray-700"
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
          </div>
        </div>
      </div>

      <!-- Section 1b: Income -->
      <div class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">Income</h2>

        <div v-if="incomeCategories.length === 0" class="text-sm text-gray-500">
          No income this month
        </div>

        <div v-else class="space-y-2">
          <div
            v-for="cat in incomeCategories"
            :key="`${cat.type}-${cat.category_id}`"
            class="flex items-center justify-between px-3 py-2 bg-gray-800 rounded-lg border border-gray-700"
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
          </div>
        </div>
      </div>

      <!-- Section 2: Family Balances -->
      <div v-if="summary.member_balances.length > 0" class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">Family Balances</h2>

        <div class="space-y-2">
          <div
            v-for="balance in summary.member_balances"
            :key="balance.user_id"
            class="flex items-center justify-between px-3 py-2 bg-gray-800 rounded-lg border border-gray-700"
          >
            <span class="text-sm text-gray-300">
              <span v-if="balance.direction === 'they_owe_you'">
                {{ balance.user_name }} owes you
              </span>
              <span v-else>
                You owe {{ balance.user_name }}
              </span>
            </span>
            <span
              :class="balance.direction === 'they_owe_you' ? 'text-green-400' : 'text-red-400'"
              class="text-sm font-medium"
            >
              {{ formatCurrency(balance.net_amount) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Section 3: Closeout Rules Preview -->
      <div class="px-4 mt-6">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">
          {{ isHardClosed ? 'Closeout Results' : 'Projected Closeout' }}
        </h2>

        <div v-if="summary.rule_preview.basis.gross_income <= 0" class="text-sm text-gray-500 mb-4">
          No income recorded — closeout rules will not run.
        </div>

        <template v-else>
          <!-- Summary row -->
          <div class="px-3 py-2 mb-4 text-xs text-gray-400 bg-gray-800 rounded-lg">
            Gross Income: <span class="text-gray-200">{{ formatCurrency(summary.rule_preview.basis.gross_income) }}</span>
            | Expenses: <span class="text-gray-200">{{ formatCurrency(summary.rule_preview.basis.total_expenses) }}</span>
            | Remaining: <span class="text-gray-200">{{ formatCurrency(summary.rule_preview.basis.remaining_after_expenses) }}</span>
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

              <!-- Allocation description + amount -->
              <div class="flex items-center justify-between px-2 mb-2">
                <span class="text-xs text-gray-400">{{ formatAllocation(rule) }}</span>
                <span
                  :class="rule.projected_amount > 0 ? 'text-green-400' : 'text-gray-400'"
                  class="text-sm font-medium"
                >
                  {{ formatCurrency(rule.projected_amount) }}
                </span>
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
    </div>
  </div>
</template>
