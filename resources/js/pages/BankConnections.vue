<template>
  <div class="pb-32 px-4 pt-4 max-w-lg mx-auto w-full min-w-0">
    <header class="mb-6">
      <h1 class="text-2xl font-bold text-white">Bank Connections</h1>
    </header>

    <div
      v-if="statusMessage"
      class="mb-4 rounded-xl border border-amber-700/60 bg-amber-950/40 px-3 py-2 text-sm text-amber-100"
    >
      {{ statusMessage }}
    </div>

    <div
      v-if="pendingCount > 0"
      class="mb-4 flex flex-col gap-3 rounded-xl border border-amber-600/50 bg-amber-950/35 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
    >
      <p class="text-sm font-medium text-amber-100">
        You have {{ pendingCount }} {{ pendingCount === 1 ? 'transaction' : 'transactions' }} to review
      </p>
      <router-link
        to="/plaid/import-review"
        class="inline-flex shrink-0 items-center justify-center rounded-lg bg-amber-500 px-4 py-2.5 text-sm font-semibold text-gray-900 transition-colors hover:bg-amber-400 active:bg-amber-500"
      >
        Review
      </router-link>
    </div>

    <div
      v-if="linkedSuccessItem"
      class="mb-4 rounded-xl border border-emerald-700/50 bg-emerald-950/30 px-4 py-3 space-y-3"
    >
      <p class="text-sm text-emerald-100">
        <span class="font-semibold text-white">{{ linkedSuccessItem.institution_name || 'Bank' }}</span>
        is connected. Match past activity so new imports stay accurate.
      </p>
      <button
        type="button"
        class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-emerald-500 sm:w-auto"
        @click="goCalibrate(linkedSuccessItem.id)"
      >
        Calibrate Now
      </button>
    </div>

    <button
      type="button"
      class="w-full rounded-xl bg-blue-600 py-3.5 px-4 text-center text-base font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 mb-3 min-h-[48px]"
      :disabled="connecting"
      @click="openPlaidLink"
    >
      {{ connecting ? 'Opening…' : 'Connect a Bank' }}
    </button>
    <p class="mb-6 text-xs leading-relaxed text-gray-500">
      <span class="font-medium text-gray-400">Apple Card:</span>
      Plaid only lists it when your Plaid account supports FinanceKit / Apple Card and you complete the flow on a compatible
      <span class="text-gray-400">iPhone</span>
      with a recent iOS—often with Wallet permission for this site. It may not appear in desktop browsers or if Apple or Plaid has not enabled the integration for your region.
    </p>

    <h2 class="text-lg font-semibold text-white mb-3">Connected banks</h2>
    <p v-if="loadingItems" class="text-gray-400 text-sm">Loading…</p>
    <ul v-else-if="items.length" class="space-y-3">
      <li
        v-for="item in items"
        :key="item.id"
        class="flex flex-col gap-3 rounded-xl border border-gray-700 bg-gray-800/80 p-4"
      >
        <div class="min-w-0">
          <p class="truncate font-medium text-white">
            {{ item.institution_name || 'Bank' }}
          </p>
          <p class="truncate text-xs text-gray-500">
            Linked {{ formatLinkedDate(item.created_at) }}
          </p>
        </div>
        <div class="flex flex-col gap-2">
          <button
            type="button"
            class="min-h-[44px] rounded-lg bg-gray-700 px-3 py-2.5 text-sm font-medium text-white transition-colors hover:bg-gray-600 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="syncMonthId === item.id"
            @click="syncThisMonth(item.id)"
          >
            {{ syncMonthId === item.id ? 'Syncing…' : 'Sync this month' }}
          </button>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="min-h-[44px] flex-1 rounded-lg border border-gray-600 bg-gray-800 px-3 py-2.5 text-sm font-medium text-white transition-colors hover:bg-gray-700 sm:flex-none"
              @click="goCalibrate(item.id)"
            >
              Calibrate
            </button>
            <button
              type="button"
              class="min-h-[44px] flex-1 rounded-lg border border-red-800/80 bg-red-950/40 px-3 py-2.5 text-sm font-medium text-red-100 transition-colors hover:bg-red-900/50 sm:flex-none"
              @click="disconnect(item.id)"
            >
              Disconnect
            </button>
          </div>
        </div>
      </li>
    </ul>
    <p v-else class="text-gray-400 text-sm">No banks linked yet.</p>

    <!-- Toast -->
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
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useApi } from '../composables/useApi';

const PLAID_SCRIPT = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';

const router = useRouter();
const { get, post, del } = useApi();

const loadingItems = ref(true);
const items = ref([]);
const connecting = ref(false);
const syncMonthId = ref(null);
const statusMessage = ref('');
const pendingCount = ref(0);
const linkedSuccessItem = ref(null);
const toast = ref({ message: '', variant: 'success' });

let toastTimer = null;

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

function formatLinkedDate(iso) {
  if (!iso) {
    return '';
  }
  try {
    return new Date(iso).toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return String(iso);
  }
}

function goCalibrate(itemId) {
  router.push(`/plaid/calibrate/${itemId}`);
}

function loadPlaidScript() {
  return new Promise((resolve, reject) => {
    if (window.Plaid) {
      resolve();
      return;
    }
    const existing = document.querySelector(`script[src="${PLAID_SCRIPT}"]`);
    if (existing) {
      existing.addEventListener('load', () => resolve());
      existing.addEventListener('error', reject);
      return;
    }
    const s = document.createElement('script');
    s.src = PLAID_SCRIPT;
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Failed to load Plaid Link'));
    document.head.appendChild(s);
  });
}

async function loadPendingCount() {
  try {
    const data = await get('/plaid/pending-imports');
    const regular = Array.isArray(data?.pending) ? data.pending : [];
    const transferRows = Array.isArray(data?.transfers) ? data.transfers : [];
    pendingCount.value = regular.length + transferRows.length;
  } catch {
    pendingCount.value = 0;
  }
}

async function loadItems() {
  loadingItems.value = true;
  statusMessage.value = '';
  try {
    items.value = await get('/plaid/items');
  } catch (err) {
    console.error(err);
    statusMessage.value = err.response?.data?.message || 'Could not load bank connections.';
  } finally {
    loadingItems.value = false;
  }
}

async function openPlaidLink() {
  statusMessage.value = '';
  linkedSuccessItem.value = null;
  connecting.value = true;
  try {
    await loadPlaidScript();
    const { link_token: linkToken, message } = await get('/plaid/link-token');
    if (!linkToken) {
      statusMessage.value = message || 'Plaid is not configured on the server.';
      return;
    }

    const handler = window.Plaid.create({
      token: linkToken,
      onSuccess: async (publicToken) => {
        try {
          const res = await post('/plaid/exchange', { public_token: publicToken });
          linkedSuccessItem.value = res.item ?? null;
          await loadItems();
          await loadPendingCount();
        } catch (err) {
          console.error(err);
          statusMessage.value =
            err.response?.data?.message ||
            err.response?.data?.error ||
            'Could not finish linking your bank.';
        }
      },
      onExit: (err) => {
        if (err?.error_message) {
          statusMessage.value = err.error_message;
        }
      },
    });
    handler.open();
  } catch (err) {
    console.error(err);
    statusMessage.value =
      err.response?.data?.message || err.message || 'Could not start Plaid Link.';
  } finally {
    connecting.value = false;
  }
}

async function syncThisMonth(id) {
  statusMessage.value = '';
  syncMonthId.value = id;
  try {
    const res = await post(`/plaid/items/${id}/sync-month`, {});
    const q = Number(res?.pending_created ?? 0);
    const a = Number(res?.auto_created ?? 0);
    let msg = 'This month is up to date.';
    if (q > 0 || a > 0) {
      const parts = [];
      if (q > 0) {
        parts.push(`${q} queued for review`);
      }
      if (a > 0) {
        parts.push(`${a} auto-created`);
      }
      msg = `Sync complete: ${parts.join(', ')}.`;
    }
    showToast(msg, 'success');
    await loadPendingCount();
  } catch (err) {
    console.error(err);
    showToast(err.response?.data?.message || 'Sync failed.', 'error');
  } finally {
    syncMonthId.value = null;
  }
}

async function disconnect(id) {
  if (!window.confirm('Disconnect this bank at Plaid and remove it from Money Tracker?')) {
    return;
  }
  statusMessage.value = '';
  try {
    await del(`/plaid/items/${id}`);
    if (linkedSuccessItem.value?.id === id) {
      linkedSuccessItem.value = null;
    }
    await loadItems();
    await loadPendingCount();
    showToast('Bank disconnected.', 'success');
  } catch (err) {
    console.error(err);
    showToast(err.response?.data?.message || 'Could not disconnect.', 'error');
  }
}

onMounted(async () => {
  await loadItems();
  await loadPendingCount();
});
</script>
