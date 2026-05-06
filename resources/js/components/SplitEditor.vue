<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-medium text-gray-300">Split Details</h3>
      <button
        type="button"
        @click="equalSplit"
        class="text-xs font-medium text-blue-400 hover:text-blue-300 px-3 py-1 bg-blue-900/30 rounded-full transition-colors"
      >
        Equal Split
      </button>
    </div>

    <!-- Split Rows -->
    <div v-if="familyUsers.length === 0" class="p-4 text-center bg-gray-800 rounded-lg border border-gray-700">
      <p class="text-gray-400 text-sm">No family members found.</p>
      <p class="text-gray-500 text-xs mt-1">Make sure your account is assigned to a family.</p>
    </div>
    <div v-else class="space-y-3">
      <div
        v-for="(user, index) in familyUsers"
        :key="user.id"
        class="bg-gray-800 border border-gray-700 rounded-lg p-3 space-y-2"
      >
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-300 font-medium">{{ user.name }}</span>
          <span
            class="text-xs font-medium px-2 py-1 rounded"
            :class="splitPercentage(index) > 0
              ? 'bg-green-900/30 text-green-400'
              : 'bg-gray-700 text-gray-500'"
          >
            <span v-if="mode === 'percentage'">{{ splitPercentage(index).toFixed(1) }}%</span>
            <span v-else>{{ formatCurrency(userAmount(index)) }}</span>
          </span>
        </div>

        <div class="flex items-center gap-2">
          <input
            :value="splitPercentage(index)"
            @input="updateSplitPercentage(index, $event.target.value)"
            v-bind="mobileDecimalNumberAttrs"
            type="number"
            min="0"
            max="100"
            step="0.1"
            class="flex-1 px-3 py-1 bg-gray-700 border border-gray-600 rounded text-white text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors"
          />
          <span class="text-gray-400 text-sm font-medium">%</span>
        </div>
      </div>
    </div>

    <!-- Total Percentage Display -->
    <div class="mt-4 pt-4 border-t border-gray-700">
      <div class="flex items-center justify-between">
        <span class="text-sm text-gray-400">Total</span>
        <div class="flex items-center gap-2">
          <span
            class="text-sm font-bold px-3 py-1 rounded"
            :class="totalPercentage === 100
              ? 'bg-green-900/30 text-green-400'
              : 'bg-red-900/30 text-red-400'"
          >
            {{ totalPercentage.toFixed(1) }}%
          </span>
          <span v-if="mode === 'currency'" class="text-sm font-medium text-gray-300">
            {{ formatCurrency(totalAmount) }}
          </span>
        </div>
      </div>
      <p v-if="totalPercentage !== 100" class="text-xs text-red-400 mt-2">
        ⚠ Percentages must add up to 100%
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { equalSharePercentages } from '../support/equalFamilySplit.js';
import { mobileDecimalNumberAttrs } from '../support/mobileNumericInputAttrs.js';

const props = defineProps({
  familyUsers: {
    type: Array,
    required: true,
  },
  totalAmount: {
    type: Number,
    required: true,
  },
  initialSplits: {
    type: Array,
    default: () => [],
  },
  mode: {
    type: String,
    default: 'currency',
    validator: (value) => ['currency', 'percentage'].includes(value),
  },
});

const emit = defineEmits(['update:splits']);

const splits = ref([]);
const isSyncingFromProps = ref(false);

function initializeSplits() {
  isSyncingFromProps.value = true;
  splits.value = props.familyUsers.map(user => {
    const existing = props.initialSplits?.find(s => s.user_id === user.id);
    return {
      user_id: user.id,
      share_percentage: existing?.share_percentage ?? existing?.percentage ?? 0,
    };
  });
  nextTick(() => {
    isSyncingFromProps.value = false;
  });
}

function splitPercentage(index) {
  return splits.value[index]?.share_percentage || 0;
}

function updateSplitPercentage(index, value) {
  if (!splits.value[index]) {
    splits.value[index] = {
      user_id: props.familyUsers[index]?.id,
      share_percentage: 0,
    };
  }

  splits.value[index].share_percentage = Number(value) || 0;
}

const totalPercentage = computed(() => {
  return splits.value.reduce((sum, split) => sum + (split.share_percentage || 0), 0);
});

const totalAmount = computed(() => {
  return (props.totalAmount || 0) * (totalPercentage.value / 100);
});

function userAmount(index) {
  const percentage = splitPercentage(index);
  return (percentage / 100) * (props.totalAmount || 0);
}

function equalSplit() {
  const n = props.familyUsers.length;
  if (n <= 0) {
    return;
  }
  const percents = equalSharePercentages(n);
  splits.value.forEach((split, index) => {
    split.share_percentage = percents[index] ?? 0;
  });
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
}

watch(splits, () => {
  if (isSyncingFromProps.value) {
    return;
  }

  emit(
    'update:splits',
    splits.value
      .filter(s => (s.share_percentage || 0) > 0)
      .map(s => ({
        user_id: s.user_id,
        share_percentage: s.share_percentage,
      }))
  );
}, { deep: true });

watch(() => props.totalAmount, () => {
  if (isSyncingFromProps.value) {
    return;
  }

  // Re-emit when totalAmount changes
  emit(
    'update:splits',
    splits.value
      .filter(s => (s.share_percentage || 0) > 0)
      .map(s => ({
        user_id: s.user_id,
        share_percentage: s.share_percentage,
      }))
  );
});

watch(() => props.familyUsers, () => {
  initializeSplits();
}, { deep: true });

watch(() => props.initialSplits, () => {
  initializeSplits();
}, { deep: true });

initializeSplits();
</script>
