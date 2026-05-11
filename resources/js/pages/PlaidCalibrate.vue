<template>
  <div class="pb-40 px-4 pt-4 max-w-lg mx-auto w-full min-w-0">
    <header class="mb-4 flex items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-white">Calibrate</h1>
        <p class="mt-1 text-sm text-gray-400">
          Confirm matches between your bank feed and ledger, queue stragglers for import review, then apply once.
        </p>
      </div>
      <router-link
        to="/bank-connections"
        class="shrink-0 text-sm font-medium text-blue-400 hover:text-blue-300"
      >
        Banks
      </router-link>
    </header>

    <p v-if="!itemId" class="text-sm text-red-300">Missing bank connection.</p>

    <div v-else-if="loading" class="flex flex-col items-center justify-center py-16">
      <svg class="h-9 w-9 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
      </svg>
      <p class="mt-3 text-sm text-gray-400">Loading calibration data…</p>
    </div>

    <p v-else-if="loadError" class="rounded-lg border border-red-800/60 bg-red-950/30 px-3 py-2 text-sm text-red-200">
      {{ loadError }}
    </p>

    <template v-else-if="successResult">
      <div class="rounded-xl border border-emerald-700/50 bg-emerald-950/25 px-4 py-5 text-center">
        <p class="text-lg font-semibold text-white">Calibration applied</p>
        <p class="mt-2 text-sm text-emerald-100">
          {{ successResult.confirmed_linked }} pair(s) linked ·
          {{ successResult.imported_pending }} queued for import review
        </p>
        <router-link
          to="/plaid/import-review"
          class="mt-5 inline-flex min-h-[48px] w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-emerald-500 sm:w-auto"
        >
          Go to Import Review
        </router-link>
        <router-link
          to="/bank-connections"
          class="mt-2 block text-center text-sm text-gray-400 hover:text-gray-300"
        >
          Back to Bank Connections
        </router-link>
      </div>
    </template>

    <template v-else>
      <!-- Tab / section switcher -->
      <div class="mb-4 flex gap-1 rounded-xl border border-gray-700 bg-gray-900/80 p-1">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          class="min-h-[44px] flex-1 rounded-lg px-2 py-2 text-center text-xs font-semibold transition-colors sm:text-sm"
          :class="
            activeSection === tab.id
              ? 'bg-blue-600 text-white'
              : 'text-gray-400 hover:bg-gray-800 hover:text-white'
          "
          @click="activeSection = tab.id"
        >
          {{ tab.label }}
          <span class="block text-[10px] font-normal opacity-80 sm:inline sm:text-xs">({{ tab.count }})</span>
        </button>
      </div>

      <!-- Matched pairs -->
      <section v-show="activeSection === 'matched'" class="space-y-3">
        <p v-if="matched.length === 0" class="text-sm text-gray-500">No automatic matches in this window.</p>
        <template v-else>
          <div
            v-for="row in paginatedMatched"
            :key="plaidTid(row.plaid)"
            class="rounded-xl border border-gray-700 bg-gray-800/70 p-3"
          >
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
              <span class="text-xs font-medium text-gray-500">Match score</span>
              <span class="rounded-full bg-gray-900 px-2 py-0.5 text-xs text-gray-300">
                {{ formatScore(row.score) }}
              </span>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
              <div class="rounded-lg border border-gray-600/60 bg-gray-900/50 p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Plaid</p>
                <p class="mt-1 font-semibold text-white">{{ plaidMerchant(row.plaid) }}</p>
                <p class="mt-1 text-sm text-gray-400">
                  {{ plaidDate(row.plaid) }}
                  <span class="mx-1 text-gray-600">·</span>
                  <span :class="plaidIsIncome(row.plaid) ? 'text-emerald-400' : 'text-red-400'">
                    {{ plaidAmountLabel(row.plaid) }}
                  </span>
                </p>
              </div>
              <div class="rounded-lg border border-gray-600/60 bg-gray-900/50 p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ledger</p>
                <p class="mt-1 text-sm text-white">{{ row.ledger?.description || '—' }}</p>
                <p v-if="row.ledger?.category" class="mt-1 text-xs text-gray-400">
                  {{ row.ledger.category.icon ? `${row.ledger.category.icon} ` : '' }}{{ row.ledger.category.name }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                  {{ row.ledger?.date }} · {{ formatLedgerMoney(row.ledger) }}
                </p>
              </div>
            </div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
              <button
                type="button"
                class="min-h-[44px] flex-1 rounded-lg border-2 py-2.5 text-sm font-semibold transition-colors"
                :class="
                  decisionForMatched(row) === 'confirm'
                    ? 'border-emerald-500 bg-emerald-950/40 text-emerald-100'
                    : 'border-transparent bg-gray-700 text-gray-200 hover:bg-gray-600'
                "
                :disabled="applySubmitting"
                @click="setMatchedDecision(row, 'confirm')"
              >
                Confirm this match
              </button>
              <button
                type="button"
                class="min-h-[44px] flex-1 rounded-lg border-2 py-2.5 text-sm font-semibold transition-colors"
                :class="
                  decisionForMatched(row) === 'unmatch'
                    ? 'border-amber-500 bg-amber-950/30 text-amber-100'
                    : 'border-transparent bg-gray-700 text-gray-200 hover:bg-gray-600'
                "
                :disabled="applySubmitting"
                @click="setMatchedDecision(row, 'unmatch')"
              >
                Unmatch
              </button>
            </div>
          </div>
          <PaginationBar
            v-if="matched.length > pageSize"
            :page="matchedPage"
            :total="matched.length"
            :page-size="pageSize"
            @update:page="matchedPage = $event"
          />
        </template>
      </section>

      <!-- Unmatched from bank -->
      <section v-show="activeSection === 'bank'" class="space-y-3">
        <p v-if="unmatchedPlaid.length === 0" class="text-sm text-gray-500">No unmatched Plaid rows in this window.</p>
        <template v-else>
          <div
            v-for="entry in paginatedUnmatchedPlaid"
            :key="plaidTid(entry.plaid)"
            class="rounded-xl border border-gray-700 bg-gray-800/70 p-3"
          >
            <p class="font-semibold text-white">{{ plaidMerchant(entry.plaid) }}</p>
            <p class="mt-1 text-sm text-gray-400">
              {{ plaidDate(entry.plaid) }}
              <span class="mx-1 text-gray-600">·</span>
              <span :class="plaidIsIncome(entry.plaid) ? 'text-emerald-400' : 'text-red-400'">
                {{ plaidAmountLabel(entry.plaid) }}
              </span>
            </p>
            <p v-if="entry.suggestion?.category_id" class="mt-2 text-xs text-gray-400">
              Suggested: {{ suggestionTypeLabel(entry.suggestion) }}
              <span v-if="formatSuggestionConfidence(entry.suggestion)" class="text-gray-500">
                · {{ formatSuggestionConfidence(entry.suggestion) }} confidence
              </span>
            </p>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
              <button
                type="button"
                class="min-h-[44px] flex-1 rounded-lg border-2 py-2.5 text-sm font-semibold transition-colors"
                :class="
                  bankAction(entry.plaid) === 'queue'
                    ? 'border-blue-500 bg-blue-950/40 text-blue-100'
                    : 'border-transparent bg-gray-700 text-gray-200 hover:bg-gray-600'
                "
                :disabled="applySubmitting"
                @click="setBankAction(entry.plaid, 'queue')"
              >
                Queue for Review
              </button>
              <button
                type="button"
                class="min-h-[44px] flex-1 rounded-lg border-2 py-2.5 text-sm font-semibold transition-colors"
                :class="
                  bankAction(entry.plaid) === 'ignore'
                    ? 'border-gray-500 bg-gray-900 text-gray-200'
                    : 'border-transparent bg-gray-700 text-gray-200 hover:bg-gray-600'
                "
                :disabled="applySubmitting"
                @click="setBankAction(entry.plaid, 'ignore')"
              >
                Ignore
              </button>
            </div>
          </div>
          <PaginationBar
            v-if="unmatchedPlaid.length > pageSize"
            :page="bankPage"
            :total="unmatchedPlaid.length"
            :page-size="pageSize"
            @update:page="bankPage = $event"
          />
        </template>
      </section>

      <!-- Unmatched ledger (read-only) -->
      <section v-show="activeSection === 'ledger'" class="space-y-3">
        <p v-if="unmatchedLedger.length === 0" class="text-sm text-gray-500">No unmatched ledger rows in this window.</p>
        <template v-else>
          <div
            v-for="tx in paginatedLedger"
            :key="tx.id"
            class="rounded-xl border border-gray-700 bg-gray-800/50 p-3"
          >
            <p class="text-sm text-white">{{ tx.description || '—' }}</p>
            <p v-if="tx.category" class="mt-1 text-xs text-gray-400">
              {{ tx.category.icon ? `${tx.category.icon} ` : '' }}{{ tx.category.name }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ tx.date }}
              <span class="mx-1">·</span>
              <span :class="tx.type === 'income' ? 'text-emerald-400' : 'text-red-400'">
                {{ tx.type === 'income' ? '+' : '−' }}{{ formatAbsMoney(tx.amount) }}
              </span>
            </p>
          </div>
          <PaginationBar
            v-if="unmatchedLedger.length > pageSize"
            :page="ledgerPage"
            :total="unmatchedLedger.length"
            :page-size="pageSize"
            @update:page="ledgerPage = $event"
          />
        </template>
      </section>

      <p v-if="applyError" class="mt-4 rounded-lg border border-red-800/60 bg-red-950/30 px-3 py-2 text-sm text-red-200">
        {{ applyError }}
      </p>

      <!-- Sticky footer -->
      <div
        class="fixed left-0 right-0 z-40 border-t border-gray-800 bg-gray-900/95 px-4 py-3 backdrop-blur-sm bottom-[calc(5.5rem+env(safe-area-inset-bottom,0px))] sm:bottom-[calc(7rem+env(safe-area-inset-bottom,0px))]"
      >
        <div class="mx-auto max-w-lg">
          <button
            type="button"
            class="min-h-[52px] w-full rounded-xl bg-blue-600 py-3.5 text-base font-semibold text-white transition-colors hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="applySubmitting"
            @click="applyCalibration"
          >
            {{ applySubmitting ? 'Applying…' : 'Apply Calibration' }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, defineComponent, h } from 'vue';
import { useRoute } from 'vue-router';
import { useApi } from '../composables/useApi';

const HIGH_SCORE = 0.9;
const pageSize = 100;

const PaginationBar = defineComponent({
  name: 'PaginationBar',
  props: {
    page: { type: Number, required: true },
    total: { type: Number, required: true },
    pageSize: { type: Number, required: true },
  },
  emits: ['update:page'],
  setup(props, { emit }) {
    const totalPages = computed(() => Math.max(1, Math.ceil(props.total / props.pageSize)));

    return () =>
      h('div', { class: 'flex items-center justify-between gap-2 pt-2 text-sm text-gray-400' }, [
        h(
          'button',
          {
            type: 'button',
            class:
              'rounded-lg border border-gray-600 px-3 py-2 text-gray-200 disabled:opacity-40 min-h-[44px] disabled:cursor-not-allowed',
            disabled: props.page <= 1,
            onClick: () => emit('update:page', props.page - 1),
          },
          'Previous',
        ),
        h('span', { class: 'text-xs' }, `Page ${props.page} / ${totalPages.value}`),
        h(
          'button',
          {
            type: 'button',
            class:
              'rounded-lg border border-gray-600 px-3 py-2 text-gray-200 disabled:opacity-40 min-h-[44px] disabled:cursor-not-allowed',
            disabled: props.page >= totalPages.value,
            onClick: () => emit('update:page', props.page + 1),
          },
          'Next',
        ),
      ]);
  },
});

const route = useRoute();
const { get, post } = useApi();

const loading = ref(true);
const loadError = ref('');
const applySubmitting = ref(false);
const applyError = ref('');
const successResult = ref(null);

const matched = ref([]);
const unmatchedPlaid = ref([]);
const unmatchedLedger = ref([]);

const activeSection = ref('matched');
const matchedPage = ref(1);
const bankPage = ref(1);
const ledgerPage = ref(1);

const matchedDecision = reactive({});
const bankDecision = reactive({});

const itemId = computed(() => {
  const raw = route.params.itemId;
  if (raw === undefined || raw === null || raw === '') {
    return null;
  }
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
});

const tabs = computed(() => [
  { id: 'matched', label: 'Matched', count: matched.value.length },
  { id: 'bank', label: 'From bank', count: unmatchedPlaid.value.length },
  { id: 'ledger', label: 'In ledger', count: unmatchedLedger.value.length },
]);

const paginatedMatched = computed(() => {
  const start = (matchedPage.value - 1) * pageSize;

  return matched.value.slice(start, start + pageSize);
});

const paginatedUnmatchedPlaid = computed(() => {
  const start = (bankPage.value - 1) * pageSize;

  return unmatchedPlaid.value.slice(start, start + pageSize);
});

const paginatedLedger = computed(() => {
  const start = (ledgerPage.value - 1) * pageSize;

  return unmatchedLedger.value.slice(start, start + pageSize);
});

function plaidTid(plaid) {
  const id = plaid?.transaction_id;

  return typeof id === 'string' && id !== '' ? id : '';
}

function plaidMerchant(plaid) {
  return plaid?.merchant_name || plaid?.name || 'Transaction';
}

function plaidDate(plaid) {
  const d = plaid?.date || plaid?.authorized_date;

  return typeof d === 'string' ? d.slice(0, 10) : '';
}

function plaidIsIncome(plaid) {
  const amt = Number(plaid?.amount);

  return amt < 0;
}

function plaidAmountLabel(plaid) {
  const amt = Number(plaid?.amount);
  const abs = Number.isFinite(amt) ? Math.abs(amt) : 0;
  const str = abs.toLocaleString(undefined, { style: 'currency', currency: 'USD' });

  return plaidIsIncome(plaid) ? `+${str}` : `−${str}`;
}

function formatLedgerMoney(ledger) {
  if (!ledger) {
    return '';
  }
  const n = Number(ledger.amount);

  return Number.isFinite(n)
    ? Math.abs(n).toLocaleString(undefined, { style: 'currency', currency: 'USD' })
    : '';
}

function formatAbsMoney(amount) {
  const n = Number(amount);

  return Number.isFinite(n)
    ? Math.abs(n).toLocaleString(undefined, { style: 'currency', currency: 'USD' })
    : String(amount);
}

function formatScore(score) {
  const n = Number(score);

  return Number.isFinite(n) ? `${Math.round(n * 100)}%` : '—';
}

function suggestionTypeLabel(s) {
  const t = s?.type;

  return t === 'income' ? 'Income' : 'Expense';
}

function formatSuggestionConfidence(s) {
  const n = Number(s?.confidence_score);
  if (!Number.isFinite(n)) {
    return '';
  }
  const pct = n <= 1 && n >= 0 ? Math.round(n * 100) : Math.round(n);

  return `${pct}%`;
}

function decisionForMatched(row) {
  const tid = plaidTid(row.plaid);

  return matchedDecision[tid] || 'none';
}

function setMatchedDecision(row, mode) {
  const tid = plaidTid(row.plaid);
  if (!tid) {
    return;
  }
  if (matchedDecision[tid] === mode) {
    matchedDecision[tid] = 'none';
  } else {
    matchedDecision[tid] = mode;
  }
}

function bankAction(plaid) {
  const tid = plaidTid(plaid);

  return bankDecision[tid] || 'ignore';
}

function setBankAction(plaid, mode) {
  const tid = plaidTid(plaid);
  if (!tid) {
    return;
  }
  bankDecision[tid] = mode;
}

function resetState() {
  successResult.value = null;
  applyError.value = '';
  matched.value = [];
  unmatchedPlaid.value = [];
  unmatchedLedger.value = [];
  matchedPage.value = 1;
  bankPage.value = 1;
  ledgerPage.value = 1;
  activeSection.value = 'matched';
  Object.keys(matchedDecision).forEach((k) => delete matchedDecision[k]);
  Object.keys(bankDecision).forEach((k) => delete bankDecision[k]);
}

function initDecisionsFromData() {
  Object.keys(matchedDecision).forEach((k) => delete matchedDecision[k]);
  Object.keys(bankDecision).forEach((k) => delete bankDecision[k]);

  for (const row of matched.value) {
    const tid = plaidTid(row.plaid);
    if (!tid) {
      continue;
    }
    const score = Number(row.score);
    matchedDecision[tid] = Number.isFinite(score) && score >= HIGH_SCORE ? 'confirm' : 'none';
  }
  for (const entry of unmatchedPlaid.value) {
    const tid = plaidTid(entry.plaid);
    if (tid) {
      bankDecision[tid] = 'ignore';
    }
  }
}

async function loadCalibration() {
  if (itemId.value === null) {
    return;
  }
  loading.value = true;
  loadError.value = '';
  successResult.value = null;
  try {
    const data = await get(`/plaid/items/${itemId.value}/calibrate`);
    matched.value = Array.isArray(data?.matched) ? data.matched : [];
    unmatchedPlaid.value = Array.isArray(data?.unmatched_plaid) ? data.unmatched_plaid : [];
    unmatchedLedger.value = Array.isArray(data?.unmatched_ledger) ? data.unmatched_ledger : [];
    initDecisionsFromData();
  } catch (err) {
    console.error(err);
    loadError.value = err.response?.data?.message || 'Could not load calibration data.';
    resetState();
  } finally {
    loading.value = false;
  }
}

function buildPayload() {
  const confirmedPairs = [];
  const importSet = new Set();

  for (const row of matched.value) {
    const tid = plaidTid(row.plaid);
    if (!tid) {
      continue;
    }
    const dec = matchedDecision[tid] || 'none';
    if (dec === 'unmatch') {
      importSet.add(tid);
    } else if (dec === 'confirm') {
      const L = row.ledger;
      const catId = L?.category?.id;
      const type = L?.type;
      if (!catId || (type !== 'income' && type !== 'expense')) {
        continue;
      }
      const pair = {
        plaid_transaction_id: tid,
        ledger_transaction_id: L.id,
        category_id: catId,
        type,
      };
      if (L.fund_id != null) {
        pair.fund_id = L.fund_id;
      }
      confirmedPairs.push(pair);
    }
  }

  for (const entry of unmatchedPlaid.value) {
    const tid = plaidTid(entry.plaid);
    if (tid && bankDecision[tid] === 'queue') {
      importSet.add(tid);
    }
  }

  return {
    confirmed_pairs: confirmedPairs,
    import_as_new: Array.from(importSet),
  };
}

async function applyCalibration() {
  if (itemId.value === null) {
    return;
  }
  applyError.value = '';
  applySubmitting.value = true;
  try {
    const body = buildPayload();
    const res = await post(`/plaid/items/${itemId.value}/calibrate`, body);
    successResult.value = {
      confirmed_linked: Number(res?.confirmed_linked ?? 0),
      imported_pending: Number(res?.imported_pending ?? 0),
    };
  } catch (err) {
    console.error(err);
    applyError.value = err.response?.data?.message || 'Could not apply calibration.';
  } finally {
    applySubmitting.value = false;
  }
}

watch(
  () => route.params.itemId,
  () => {
    resetState();
    void loadCalibration();
  },
);

watch(activeSection, () => {
  matchedPage.value = Math.min(matchedPage.value, Math.max(1, Math.ceil(matched.value.length / pageSize) || 1));
  bankPage.value = Math.min(bankPage.value, Math.max(1, Math.ceil(unmatchedPlaid.value.length / pageSize) || 1));
  ledgerPage.value = Math.min(ledgerPage.value, Math.max(1, Math.ceil(unmatchedLedger.value.length / pageSize) || 1));
});

onMounted(() => {
  void loadCalibration();
});
</script>
