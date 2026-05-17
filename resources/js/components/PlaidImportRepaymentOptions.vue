<template>
  <div class="space-y-3 rounded-lg border border-gray-700 bg-gray-900/40 p-3">
    <div
      class="flex cursor-pointer items-center justify-between"
      :class="disabled ? 'cursor-not-allowed opacity-60' : ''"
      role="button"
      tabindex="0"
      @click="!disabled && (model.is_repayment_mode = !model.is_repayment_mode)"
      @keydown.enter.prevent="!disabled && (model.is_repayment_mode = !model.is_repayment_mode)"
      @keydown.space.prevent="!disabled && (model.is_repayment_mode = !model.is_repayment_mode)"
    >
      <div>
            <p class="text-sm font-medium text-gray-300">Family member paying me back</p>
            <p class="mt-0.5 text-xs text-gray-500">Another household member is reimbursing you for expenses you paid on their behalf. This creates the same mirror expense on their account as if they had recorded the repayment.</p>
      </div>
      <div
        class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
        :class="model.is_repayment_mode ? 'bg-blue-600' : 'bg-gray-700'"
      >
        <div
          class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
          :class="model.is_repayment_mode ? 'translate-x-5' : 'translate-x-1'"
        />
      </div>
    </div>

    <template v-if="model.is_repayment_mode">
      <div>
        <label class="block text-xs font-medium text-gray-400">Family member repaying you</label>
        <select
          v-model.number="model.repayment_for_user_id"
          class="mt-1 min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none disabled:opacity-50"
          :disabled="disabled"
        >
          <option :value="null" disabled>Select family member</option>
          <option v-for="member in otherFamilyMembers" :key="member.id" :value="member.id">
            {{ member.name }}
          </option>
        </select>
      </div>

      <div v-if="model.repayment_for_user_id">
        <label class="block text-xs font-medium text-gray-400">Expenses being repaid</label>
        <p class="mb-2 text-xs text-gray-500">Select the expenses from your account that this payment covers</p>
        <div v-if="repayableExpensesLoading" class="text-xs text-gray-400">Loading expenses...</div>
        <div v-else-if="repayableExpenses.length === 0" class="text-xs text-gray-400">No eligible expenses found</div>
        <div v-else class="max-h-48 space-y-1.5 overflow-y-auto pr-1">
          <div
            v-for="tx in repayableExpenses"
            :key="tx.id"
            class="flex cursor-pointer items-center justify-between rounded-md border px-2.5 py-2 transition-colors"
            :class="
              isRepaymentLinkSelected(tx.id)
                ? 'border-blue-500 bg-blue-900/20 text-blue-100'
                : 'border-gray-600 bg-gray-700/50 text-gray-300 hover:border-gray-500'
            "
            @click="!disabled && (isRepaymentLinkSelected(tx.id) ? removeRepaymentLink(tx.id) : addRepaymentLink(tx))"
          >
            <div class="flex min-w-0 items-center gap-2">
              <span v-if="tx.category?.icon" class="shrink-0 text-sm">{{ tx.category.icon }}</span>
              <div class="min-w-0">
                <p class="truncate text-xs font-medium">{{ tx.category?.name ?? 'Uncategorized' }}</p>
                <p v-if="tx.description" class="truncate text-[10px] text-gray-400">{{ tx.description }}</p>
                <p class="text-[10px] text-gray-500">{{ tx.transaction_date }}</p>
              </div>
            </div>
            <span class="ml-2 shrink-0 text-xs font-semibold text-red-400">
              -{{ formatCurrency(Number(tx.amount)) }}
            </span>
          </div>
        </div>

        <div v-if="repaymentLinks.length > 0" class="mt-2 flex justify-between text-xs">
          <span class="text-gray-400">Selected total:</span>
          <span
            class="font-semibold"
            :class="repaymentLinksTotalMatchesAmount ? 'text-green-400' : 'text-red-400'"
          >
            {{ formatCurrency(repaymentLinksTotal) }}
            <span v-if="!repaymentLinksTotalMatchesAmount">
              ({{ amountLabel }} is {{ formatCurrency(parsedAmount) }})
            </span>
          </span>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useApi } from '../composables/useApi';
import { useAuth } from '../composables/useAuth';

const props = defineProps({
  model: {
    type: Object,
    required: true,
  },
  amount: {
    type: [Number, String],
    default: 0,
  },
  amountLabel: {
    type: String,
    default: 'amount',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  familyUsers: {
    type: Array,
    default: () => [],
  },
});

const { get } = useApi();
const { user } = useAuth();

const repayableExpenses = ref([]);
const repayableExpensesLoading = ref(false);

const parsedAmount = computed(() => {
  const parsed = parseFloat(props.amount);

  return Number.isFinite(parsed) ? parsed : 0;
});

const repaymentLinks = computed(() =>
  Array.isArray(props.model.repayment_links) ? props.model.repayment_links : [],
);

const otherFamilyMembers = computed(() =>
  props.familyUsers.filter((member) => Number(member.id) !== Number(user.value?.id)),
);

const repaymentLinksTotal = computed(() =>
  repaymentLinks.value.reduce((sum, link) => sum + (parseFloat(link.amount) || 0), 0),
);

const repaymentLinksTotalMatchesAmount = computed(
  () => Math.abs(repaymentLinksTotal.value - parsedAmount.value) < 0.01,
);

function formatCurrency(amount) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(
    Number(amount) || 0,
  );
}

async function loadRepayableExpenses() {
  repayableExpensesLoading.value = true;
  try {
    const data = await get('/transactions/repayable-expenses');
    repayableExpenses.value = Array.isArray(data) ? data : [];
  } catch {
    repayableExpenses.value = [];
  } finally {
    repayableExpensesLoading.value = false;
  }
}

function ensureRepaymentLinksArray() {
  if (!Array.isArray(props.model.repayment_links)) {
    props.model.repayment_links = [];
  }
}

function addRepaymentLink(tx) {
  ensureRepaymentLinksArray();
  if (isRepaymentLinkSelected(tx.id)) {
    return;
  }
  props.model.repayment_links.push({
    transaction_id: tx.id,
    amount: String(tx.amount),
  });
}

function removeRepaymentLink(txId) {
  ensureRepaymentLinksArray();
  props.model.repayment_links = props.model.repayment_links.filter(
    (link) => Number(link.transaction_id) !== Number(txId),
  );
}

function isRepaymentLinkSelected(txId) {
  return repaymentLinks.value.some((link) => Number(link.transaction_id) === Number(txId));
}

watch(
  () => props.model.is_repayment_mode,
  (enabled) => {
    if (enabled) {
      if ('income_debt_mode' in props.model) {
        props.model.income_debt_mode = 'none';
        props.model.income_existing_debt_id = null;
        props.model.income_new_is_family_debt = false;
        props.model.income_new_is_interfamily = false;
        props.model.income_new_creditor_id = null;
        props.model.income_new_creditor_name = '';
        props.model.income_new_description = '';
        props.model.income_new_interest_enabled = false;
        props.model.income_new_interest_rate = 0;
      }
      ensureRepaymentLinksArray();
      loadRepayableExpenses();
      return;
    }
    props.model.repayment_for_user_id = null;
    props.model.repayment_links = [];
  },
);
</script>
