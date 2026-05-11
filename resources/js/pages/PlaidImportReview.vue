<template>
  <div class="pb-32 px-4 pt-4 max-w-lg mx-auto w-full min-w-0">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-bold text-white">Import Review</h1>
      <router-link
        to="/bank-connections"
        class="shrink-0 text-sm font-medium text-blue-400 hover:text-blue-300"
      >
        Banks
      </router-link>
    </header>

    <div
      v-if="showTabs"
      class="mb-4 flex gap-1 rounded-xl border border-gray-700 bg-gray-900/50 p-1"
      role="tablist"
    >
      <button
        type="button"
        role="tab"
        :aria-selected="activeTab === 'review'"
        class="flex min-h-[44px] min-w-0 flex-1 items-center justify-center gap-2 rounded-lg px-2 py-2 text-sm font-semibold transition-colors"
        :class="
          activeTab === 'review'
            ? 'bg-gray-800 text-white shadow-sm'
            : 'text-gray-400 hover:text-gray-200'
        "
        @click="activeTab = 'review'"
      >
        <span>To Review</span>
        <span
          class="inline-flex h-6 min-w-[1.5rem] shrink-0 items-center justify-center rounded-full bg-gray-700 px-2 text-xs font-bold text-white"
        >
          {{ pendingImports.length }}
        </span>
      </button>
      <button
        type="button"
        role="tab"
        :aria-selected="activeTab === 'transfers'"
        class="flex min-h-[44px] min-w-0 flex-1 items-center justify-center gap-2 rounded-lg px-2 py-2 text-sm font-semibold transition-colors"
        :class="
          activeTab === 'transfers'
            ? 'bg-gray-800 text-white shadow-sm'
            : 'text-gray-400 hover:text-gray-200'
        "
        @click="activeTab = 'transfers'"
      >
        <span>Transfers</span>
        <span
          class="inline-flex h-6 min-w-[1.5rem] shrink-0 items-center justify-center rounded-full bg-amber-900/80 px-2 text-xs font-bold text-amber-100 ring-1 ring-amber-700/50"
        >
          {{ transferImports.length }}
        </span>
      </button>
    </div>

    <p
      v-if="recentlyAutoCreated > 0"
      class="mb-4 rounded-xl border border-gray-700/80 bg-gray-800/60 px-3 py-2.5 text-sm text-gray-300"
    >
      We auto-imported
      {{ recentlyAutoCreated }}
      {{ recentlyAutoCreated === 1 ? 'transaction' : 'transactions' }}
      in the last 30 days based on your history.
    </p>

    <p v-if="noFamilyCategories" class="mb-4 rounded-xl border border-amber-700/50 bg-amber-950/30 px-3 py-2 text-sm text-amber-100">
      You need a family and categories before you can confirm imports. Ask an admin to assign your account to a family.
    </p>

    <p v-if="loading" class="text-gray-400 text-sm">Loading…</p>
    <p v-else-if="pageError" class="rounded-lg border border-red-800/60 bg-red-950/30 px-3 py-2 text-sm text-red-200">
      {{ pageError }}
    </p>
    <p v-else-if="allEmpty" class="rounded-xl border border-gray-700 bg-gray-800/40 px-4 py-6 text-center text-sm text-gray-300">
      All caught up! No transactions to review.
    </p>
    <template v-else>
      <div v-show="activeTab === 'review'">
        <p v-if="pendingImports.length === 0" class="mb-4 text-sm text-gray-400">
          Nothing to review right now.
        </p>
        <TransitionGroup v-else name="import-card" tag="ul" class="relative space-y-3">
          <li
            v-for="row in pendingImports"
            :key="row.id"
            class="overflow-hidden rounded-xl border border-gray-700 bg-gray-800/80"
          >
            <button
              type="button"
              class="flex w-full min-h-[48px] items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-gray-800"
              @click="toggleExpand(row)"
            >
              <div class="min-w-0 flex-1">
                <p class="font-bold text-white">
                  {{ row.merchant_name || row.raw_name || 'Transaction' }}
                </p>
                <p class="mt-1 text-sm text-gray-400">
                  {{ formatDate(row.date) }}
                  <span class="mx-1.5 text-gray-600">·</span>
                  <span :class="displayType(row) === 'income' ? 'text-emerald-400' : 'text-red-400'">
                    {{ displayType(row) === 'income' ? '+' : '−' }}{{ formatMoney(row.amount) }}
                  </span>
                </p>
                <div v-if="row.suggested_category || hasConfidence(row)" class="mt-2 flex flex-wrap items-center gap-2">
                  <span
                    v-if="row.suggested_category"
                    class="inline-flex max-w-full items-center gap-1 truncate rounded-full border border-gray-600 bg-gray-900/80 px-2.5 py-0.5 text-xs text-gray-200"
                  >
                    <span v-if="row.suggested_category.icon" class="shrink-0">{{ row.suggested_category.icon }}</span>
                    <span class="truncate">{{ row.suggested_category.name }}</span>
                  </span>
                  <span
                    v-if="hasConfidence(row)"
                    class="inline-flex rounded-full border border-gray-600 bg-gray-900/60 px-2.5 py-0.5 text-xs text-gray-400"
                  >
                    {{ formatConfidence(row.confidence_score) }} confidence
                  </span>
                </div>
              </div>
              <span class="shrink-0 text-gray-500" aria-hidden="true">
                <svg
                  class="h-5 w-5 transition-transform"
                  :class="{ 'rotate-180': expandedId === row.id }"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </span>
            </button>

            <div
              v-show="expandedId === row.id"
              class="space-y-3 border-t border-gray-700/80 px-4 pb-4 pt-3"
            >
              <div>
                <label class="mb-1.5 block text-xs font-medium text-gray-400">Type</label>
                <div class="grid grid-cols-2 gap-2">
                  <button
                    type="button"
                    class="min-h-[44px] rounded-lg py-2.5 text-sm font-medium transition-colors disabled:opacity-50"
                    :class="
                      formFor(row).type === 'expense'
                        ? 'bg-red-600 text-white'
                        : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    "
                    :disabled="actionId === row.id"
                    @click="setType(row, 'expense')"
                  >
                    Expense
                  </button>
                  <button
                    type="button"
                    class="min-h-[44px] rounded-lg py-2.5 text-sm font-medium transition-colors disabled:opacity-50"
                    :class="
                      formFor(row).type === 'income'
                        ? 'bg-emerald-600 text-white'
                        : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    "
                    :disabled="actionId === row.id"
                    @click="setType(row, 'income')"
                  >
                    Income
                  </button>
                </div>
              </div>

              <div>
                <label class="mb-1.5 block text-xs font-medium text-gray-400" :for="`cat-${row.id}`">Category</label>
                <select
                  :id="`cat-${row.id}`"
                  v-model="formFor(row).category_id"
                  class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:opacity-50"
                  :disabled="actionId === row.id"
                >
                  <option disabled value="">Select a category</option>
                  <option v-for="cat in categoriesForType(formFor(row).type)" :key="cat.id" :value="String(cat.id)">
                    {{ cat.icon ? `${cat.icon} ` : '' }}{{ cat.name }}
                  </option>
                </select>
              </div>

              <div>
                <label class="mb-1.5 block text-xs font-medium text-gray-400" :for="`desc-${row.id}`">Description</label>
                <input
                  :id="`desc-${row.id}`"
                  v-model="formFor(row).description"
                  type="text"
                  class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:opacity-50"
                  placeholder="Optional — defaults to merchant name"
                  :disabled="actionId === row.id"
                />
              </div>

              <div>
                <label class="mb-1.5 block text-xs font-medium text-gray-400" :for="`fund-${row.id}`">Fund (optional)</label>
                <select
                  :id="`fund-${row.id}`"
                  v-model="formFor(row).fund_id"
                  class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:opacity-50"
                  :disabled="actionId === row.id"
                >
                  <option value="">None</option>
                  <option v-for="fund in funds" :key="fund.id" :value="String(fund.id)">
                    {{ fund.name }}{{ fund.scope === 'family' ? ' (family)' : '' }}
                  </option>
                </select>
              </div>

              <div class="rounded-lg border border-gray-700/80 bg-gray-900/40 px-3 py-3">
                <p class="text-xs font-medium text-gray-300">Already in your books?</p>
                <p class="mt-1 text-xs text-gray-500">
                  Link this bank line to an existing transaction (same amount and type, within about 60 days). The app learns the merchant from your ledger row.
                </p>
                <div class="mt-2 flex flex-col gap-2">
                  <button
                    type="button"
                    class="min-h-[44px] w-full rounded-lg border border-gray-600 bg-gray-800/80 px-3 py-2 text-sm font-medium text-gray-100 transition-colors hover:bg-gray-700 disabled:opacity-50"
                    :disabled="actionId === row.id || loadingLinkCandidatesId === row.id"
                    @click="loadLinkCandidates(row)"
                  >
                    {{ loadingLinkCandidatesId === row.id ? 'Loading…' : (linkCandidatesMap[row.id]?.length ? 'Refresh suggestions' : 'Suggest matches') }}
                  </button>
                  <template v-if="linkCandidatesMap[row.id]?.length">
                    <label class="text-xs font-medium text-gray-400" :for="`link-${row.id}`">Pick transaction</label>
                    <select
                      :id="`link-${row.id}`"
                      v-model="linkSelectedId[row.id]"
                      class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:opacity-50"
                      :disabled="actionId === row.id"
                    >
                      <option value="">Select…</option>
                      <option v-for="c in linkCandidatesMap[row.id]" :key="c.id" :value="String(c.id)">
                        {{ formatLinkOptionLabel(c) }}
                      </option>
                    </select>
                    <button
                      type="button"
                      class="min-h-[44px] w-full rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
                      :disabled="actionId === row.id || !linkSelectedId[row.id]"
                      @click="linkPendingToLedger(row)"
                    >
                      {{ actionId === row.id ? 'Linking…' : 'Link to selected' }}
                    </button>
                  </template>
                  <p v-else-if="linkCandidatesLoaded[row.id] && !linkCandidatesMap[row.id]?.length" class="text-xs text-gray-500">
                    No close matches found. Adjust the ledger transaction date or amount, or use Confirm / Dismiss below.
                  </p>
                </div>
              </div>

              <div class="rounded-lg border border-amber-900/40 bg-amber-950/20 px-3 py-3">
                <p class="text-xs font-medium text-amber-100">Bank payment to a credit card?</p>
                <p class="mt-1 text-xs text-amber-100/80">
                  If this is paying Apple Card, Discover, etc. (not a new purchase), dismiss it here. “Always ignore” teaches the app to skip similar payments later.
                </p>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                  <button
                    type="button"
                    class="min-h-[44px] w-full rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-500 disabled:opacity-50 sm:w-auto"
                    :disabled="actionId === row.id"
                    @click="dismissPendingAsTransfer(row, true)"
                  >
                    {{ actionId === row.id ? 'Working…' : 'Always ignore' }}
                  </button>
                  <button
                    type="button"
                    class="min-h-[44px] w-full rounded-lg border border-gray-600 bg-transparent px-3 py-2 text-sm font-semibold text-gray-200 transition-colors hover:bg-gray-800/80 disabled:opacity-50 sm:w-auto"
                    :disabled="actionId === row.id"
                    @click="dismissPendingAsTransfer(row, false)"
                  >
                    Dismiss once
                  </button>
                </div>
              </div>

              <p v-if="rowErrors[row.id]" class="text-sm text-red-300">
                {{ rowErrors[row.id] }}
              </p>

              <div class="flex flex-col gap-2 sm:flex-row-reverse sm:justify-end">
                <button
                  type="button"
                  class="min-h-[48px] w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto sm:min-w-[8rem]"
                  :disabled="actionId === row.id || !formFor(row).category_id"
                  @click="confirmRow(row)"
                >
                  {{ actionId === row.id ? 'Saving…' : 'Confirm' }}
                </button>
                <button
                  type="button"
                  class="min-h-[48px] w-full rounded-xl border border-gray-600 bg-transparent px-4 py-3 text-sm font-semibold text-gray-200 transition-colors hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto sm:min-w-[8rem]"
                  :disabled="actionId === row.id"
                  @click="dismissRow(row)"
                >
                  Dismiss
                </button>
              </div>
            </div>
          </li>
        </TransitionGroup>
      </div>

      <div v-show="activeTab === 'transfers'">
        <p class="mb-4 text-sm leading-relaxed text-gray-300">
          These transactions were detected as bank-to-credit-card payments or account transfers. They are not expenses — your actual purchases are imported from the card account directly.
        </p>
        <p v-if="transferImports.length === 0" class="text-sm text-gray-500">
          No transfers detected.
        </p>
        <ul v-else class="space-y-3">
          <li
            v-for="row in transferImports"
            :key="'t-' + row.id"
            class="rounded-xl border border-gray-700 bg-gray-800/80 px-4 py-3"
          >
            <p class="font-bold text-white">
              {{ row.merchant_name || row.raw_name || 'Transaction' }}
            </p>
            <p class="mt-1 text-sm text-gray-400">
              {{ formatDate(row.date) }}
              <span class="mx-1.5 text-gray-600">·</span>
              <span :class="displayType(row) === 'income' ? 'text-emerald-400' : 'text-red-400'">
                {{ displayType(row) === 'income' ? '+' : '−' }}{{ formatMoney(row.amount) }}
              </span>
            </p>
            <p class="mt-2 text-xs text-gray-400">
              <span class="font-medium text-gray-300">{{ formatPlaidCategoryLabel(row) }}</span>
              <template v-if="institutionName(row)">
                <span class="mx-1.5 text-gray-600">·</span>
                <span>{{ institutionName(row) }}</span>
              </template>
            </p>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
              <button
                type="button"
                class="min-h-[48px] w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto sm:min-w-[10rem]"
                :disabled="actionId === row.id"
                @click="dismissTransfer(row, true)"
              >
                {{ actionId === row.id ? 'Working…' : 'Always Ignore' }}
              </button>
              <button
                type="button"
                class="min-h-[48px] w-full rounded-xl border border-gray-600 bg-transparent px-4 py-3 text-sm font-semibold text-gray-200 transition-colors hover:bg-gray-800/80 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto sm:min-w-[8rem]"
                :disabled="actionId === row.id"
                @click="dismissTransfer(row, false)"
              >
                Dismiss Once
              </button>
            </div>
          </li>
        </ul>
      </div>
    </template>

    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="translate-y-2 opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-1 opacity-0"
    >
      <div
        v-if="toast.message"
        class="fixed left-4 right-4 z-40 max-w-lg mx-auto rounded-xl border px-4 py-3 text-sm font-medium shadow-lg pointer-events-none bottom-[calc(5.5rem+env(safe-area-inset-bottom,0px))] sm:bottom-[calc(7rem+env(safe-area-inset-bottom,0px))]"
        :class="
          toast.variant === 'error'
            ? 'border-red-700/60 bg-red-950/90 text-red-100'
            : 'border-emerald-700/60 bg-emerald-950/90 text-emerald-100'
        "
        role="status"
      >
        {{ toast.message }}
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useApi } from '../composables/useApi';

const { post } = useApi();

const loading = ref(true);
const pageError = ref('');
const pendingImports = ref([]);
const transferImports = ref([]);
const activeTab = ref('review');
const categories = ref([]);
const funds = ref([]);
const recentlyAutoCreated = ref(0);
const expandedId = ref(null);
const forms = reactive({});
const rowErrors = reactive({});
const actionId = ref(null);
const toast = ref({ message: '', variant: 'success' });
const linkCandidatesMap = reactive({});
const linkCandidatesLoaded = reactive({});
const linkSelectedId = reactive({});
const loadingLinkCandidatesId = ref(null);

let toastTimer = null;

const noFamilyCategories = computed(
  () => !loading.value && !pageError.value && Array.isArray(categories.value) && categories.value.length === 0,
);

const allEmpty = computed(
  () => !loading.value && !pageError.value && pendingImports.value.length === 0 && transferImports.value.length === 0,
);

const showTabs = computed(
  () => !loading.value && !pageError.value && (pendingImports.value.length > 0 || transferImports.value.length > 0),
);

function showToast(message, variant = 'success') {
  if (toastTimer) {
    clearTimeout(toastTimer);
  }
  toast.value = { message, variant };
  toastTimer = setTimeout(() => {
    toast.value = { message: '', variant: 'success' };
    toastTimer = null;
  }, 4500);
}

function formatDate(val) {
  if (!val) {
    return '';
  }
  try {
    if (typeof val === 'string') {
      return val.slice(0, 10);
    }
    return new Date(val).toISOString().slice(0, 10);
  } catch {
    return String(val);
  }
}

function formatMoney(amount) {
  const n = Number(amount);
  if (Number.isNaN(n)) {
    return String(amount);
  }
  return Math.abs(n).toLocaleString(undefined, { style: 'currency', currency: 'USD' });
}

function formatLinkOptionLabel(c) {
  const desc = (c.description || 'No description').slice(0, 48);
  const cat = c.category ? ` (${c.category.name})` : '';

  return `${formatDate(c.date)} — ${formatMoney(c.amount)} — ${desc}${cat}`;
}

function formatPlaidCategoryLabel(row) {
  const raw = row.plaid_category_detailed || row.plaid_category_primary;
  if (!raw) {
    return 'Transfer';
  }
  return String(raw)
    .split('_')
    .map((w) => (w ? w.charAt(0) + w.slice(1).toLowerCase() : ''))
    .join(' ');
}

function institutionName(row) {
  const name = row.plaid_item?.institution_name;
  return name ? String(name) : '';
}

function merchantDisplayName(row) {
  return row.merchant_name || row.raw_name || 'this merchant';
}

function hasConfidence(score) {
  if (score === null || score === undefined || score === '') {
    return false;
  }
  const n = Number(score);
  return !Number.isNaN(n);
}

function formatConfidence(score) {
  const n = Number(score);
  if (Number.isNaN(n)) {
    return '';
  }
  const pct = n <= 1 && n >= 0 ? Math.round(n * 100) : Math.round(n);

  return `${pct}%`;
}

function displayType(row) {
  if (expandedId.value === row.id && forms[row.id]) {
    return forms[row.id].type;
  }
  return row.suggested_type === 'income' ? 'income' : 'expense';
}

function categoriesForType(type) {
  return categories.value.filter((c) => (type === 'income' ? c.is_income : c.is_expense));
}

function ensureForm(row) {
  if (forms[row.id]) {
    return;
  }
  const t = row.suggested_type === 'income' ? 'income' : 'expense';
  const pool = categories.value.filter((c) => (t === 'income' ? c.is_income : c.is_expense));
  let catId = row.suggested_category_id ?? '';
  if (catId !== '' && catId !== null) {
    catId = String(catId);
  } else {
    catId = '';
  }
  if (catId !== '' && !pool.some((c) => String(c.id) === catId)) {
    catId = pool[0] ? String(pool[0].id) : '';
  }
  if (catId === '' && pool.length) {
    catId = String(pool[0].id);
  }
  forms[row.id] = {
    type: t,
    category_id: catId,
    description: '',
    fund_id: row.suggested_fund_id != null ? String(row.suggested_fund_id) : '',
  };
}

function formFor(row) {
  ensureForm(row);

  return forms[row.id];
}

function setType(row, type) {
  const f = formFor(row);
  f.type = type;
  const pool = categories.value.filter((c) => (type === 'income' ? c.is_income : c.is_expense));
  if (!pool.some((c) => String(c.id) === String(f.category_id))) {
    f.category_id = pool[0] ? String(pool[0].id) : '';
  }
}

function toggleExpand(row) {
  if (expandedId.value === row.id) {
    expandedId.value = null;
  } else {
    expandedId.value = row.id;
    ensureForm(row);
  }
}

function removePendingRow(id) {
  const i = pendingImports.value.findIndex((r) => r.id === id);
  if (i !== -1) {
    pendingImports.value.splice(i, 1);
  }
  delete forms[id];
  delete rowErrors[id];
  delete linkCandidatesMap[id];
  delete linkCandidatesLoaded[id];
  delete linkSelectedId[id];
  if (expandedId.value === id) {
    expandedId.value = null;
  }
}

function removeTransferRow(id) {
  const i = transferImports.value.findIndex((r) => r.id === id);
  if (i !== -1) {
    transferImports.value.splice(i, 1);
  }
}

function applyDefaultTab() {
  if (transferImports.value.length > 0 && pendingImports.value.length === 0) {
    activeTab.value = 'transfers';
  } else {
    activeTab.value = 'review';
  }
}

async function loadAll() {
  loading.value = true;
  pageError.value = '';
  try {
    const [pendingRes, catRes, fundRes] = await Promise.all([
      window.axios.get('/plaid/pending-imports'),
      window.axios.get('/categories'),
      window.axios.get('/funds'),
    ]);
    pendingImports.value = Array.isArray(pendingRes.data?.pending) ? pendingRes.data.pending : [];
    transferImports.value = Array.isArray(pendingRes.data?.transfers) ? pendingRes.data.transfers : [];
    recentlyAutoCreated.value = Number(pendingRes.data?.recently_auto_created ?? 0);
    categories.value = Array.isArray(catRes.data) ? catRes.data : [];
    funds.value = Array.isArray(fundRes.data) ? fundRes.data : [];
    applyDefaultTab();
  } catch (err) {
    console.error(err);
    pageError.value = err.response?.data?.message || 'Could not load data.';
    pendingImports.value = [];
    transferImports.value = [];
  } finally {
    loading.value = false;
  }
}

async function confirmRow(row) {
  rowErrors[row.id] = '';
  const f = formFor(row);
  if (!f.category_id) {
    rowErrors[row.id] = 'Choose a category.';

    return;
  }
  actionId.value = row.id;
  try {
    const payload = {
      category_id: Number(f.category_id),
      type: f.type,
      description: f.description?.trim() || undefined,
    };
    const fundId = f.fund_id === '' || f.fund_id === null ? undefined : Number(f.fund_id);
    if (fundId !== undefined && !Number.isNaN(fundId)) {
      payload.fund_id = fundId;
    }
    await post(`/plaid/pending-imports/${row.id}/confirm`, payload);
    removePendingRow(row.id);
  } catch (err) {
    console.error(err);
    rowErrors[row.id] = err.response?.data?.message || 'Could not confirm.';
  } finally {
    actionId.value = null;
  }
}

async function dismissRow(row) {
  rowErrors[row.id] = '';
  actionId.value = row.id;
  try {
    await post(`/plaid/pending-imports/${row.id}/dismiss`, {});
    removePendingRow(row.id);
  } catch (err) {
    console.error(err);
    rowErrors[row.id] = err.response?.data?.message || 'Could not dismiss.';
  } finally {
    actionId.value = null;
  }
}

async function loadLinkCandidates(row) {
  rowErrors[row.id] = '';
  loadingLinkCandidatesId.value = row.id;
  try {
    const { data } = await window.axios.get(`/plaid/pending-imports/${row.id}/ledger-candidates`);
    const list = Array.isArray(data?.candidates) ? data.candidates : [];
    linkCandidatesMap[row.id] = list;
    linkCandidatesLoaded[row.id] = true;
    if (!linkSelectedId[row.id] && list.length === 1) {
      linkSelectedId[row.id] = String(list[0].id);
    }
  } catch (err) {
    console.error(err);
    rowErrors[row.id] = err.response?.data?.message || 'Could not load suggestions.';
    linkCandidatesMap[row.id] = [];
    linkCandidatesLoaded[row.id] = true;
  } finally {
    loadingLinkCandidatesId.value = null;
  }
}

async function linkPendingToLedger(row) {
  rowErrors[row.id] = '';
  const tid = linkSelectedId[row.id];
  if (!tid) {
    rowErrors[row.id] = 'Select a transaction to link.';

    return;
  }
  actionId.value = row.id;
  try {
    await post(`/plaid/pending-imports/${row.id}/link`, { transaction_id: Number(tid) });
    removePendingRow(row.id);
    showToast('Linked to your existing transaction. Merchant rule updated.', 'success');
  } catch (err) {
    console.error(err);
    rowErrors[row.id] = err.response?.data?.message || 'Could not link.';
  } finally {
    actionId.value = null;
  }
}

async function dismissPendingAsTransfer(row, learn) {
  actionId.value = row.id;
  try {
    const qs = learn ? '?learn=true' : '';
    await post(`/plaid/pending-imports/${row.id}/dismiss-as-transfer${qs}`, {});
    removePendingRow(row.id);
    if (learn) {
      showToast(`Future payments from ${merchantDisplayName(row)} will be automatically ignored`, 'success');
    }
  } catch (err) {
    console.error(err);
    showToast(err.response?.data?.message || 'Could not dismiss.', 'error');
  } finally {
    actionId.value = null;
  }
}

async function dismissTransfer(row, learn) {
  actionId.value = row.id;
  try {
    const qs = learn ? '?learn=true' : '';
    await post(`/plaid/pending-imports/${row.id}/dismiss-as-transfer${qs}`, {});
    removeTransferRow(row.id);
    if (learn) {
      showToast(`Future payments from ${merchantDisplayName(row)} will be automatically ignored`, 'success');
    }
  } catch (err) {
    console.error(err);
    showToast(err.response?.data?.message || 'Could not dismiss.', 'error');
  } finally {
    actionId.value = null;
  }
}

onMounted(() => {
  void loadAll();
});
</script>

<style scoped>
.import-card-move,
.import-card-enter-active,
.import-card-leave-active {
  transition: opacity 0.28s ease, transform 0.28s ease;
}

.import-card-enter-from,
.import-card-leave-to {
  opacity: 0;
  transform: translateY(6px);
}
</style>
