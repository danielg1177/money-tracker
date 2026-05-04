<template>
  <div class="pb-32">
    <!-- Header -->
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-white">Debts</h1>
        <p class="text-gray-400 text-sm mt-1">Track money owed and owing</p>
      </div>
      <button
        @click="showAddDebtModal = true"
        class="inline-flex items-center gap-2 px-3 py-2 sm:px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors p-2"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Add Debt</span>
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading debts...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ error }}</p>
      <button @click="fetchDebts" class="mt-2 text-xs text-red-400 hover:text-red-300 underline">
        Try again
      </button>
    </div>

    <!-- Content -->
    <div v-else class="space-y-6 px-4 py-4">
      <!-- Personal Debts Section -->
      <div v-if="personalDebts.length > 0">
        <h2 class="text-base font-bold text-white mb-3">Personal Debts</h2>
        <div class="space-y-3">
          <div
            v-for="debt in personalDebts"
            :key="debt.id"
            class="relative bg-gray-800 border border-gray-700 rounded-xl p-3"
            :class="{ 'opacity-50': debt.balance === 0 }"
          >
            <!-- Delete Button -->
            <button
              @click="toggleDeleteConfirm(debt.id)"
              class="absolute top-3 right-3 text-gray-500 hover:text-red-400 transition-colors p-1"
              :title="deleteConfirmDebt === debt.id ? 'Confirm delete' : 'Delete debt'"
            >
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
              </svg>
            </button>

            <!-- Status and Creditor/Debtor Info -->
            <div class="mb-3 pr-8">
              <p class="text-xs text-gray-500">
                <span v-if="debt.debtor_id === authUser.id">You owe</span>
                <span v-else>{{ debt.debtor?.name }} owes you</span>
              </p>
              <p class="text-sm font-medium" :class="debt.creditor_id ? 'text-white' : 'text-gray-300 italic'">
                {{ debt.creditor?.name || debt.debtor?.name || debt.creditor_name }}
              </p>
            </div>

            <!-- Amounts -->
            <div class="grid grid-cols-2 gap-3 mb-3">
              <div>
                <p class="text-xs text-gray-500">Original</p>
                <p class="text-sm font-medium text-gray-300">{{ formatCurrency(debt.amount) }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500">Remaining</p>
                <p
                  class="text-sm font-medium"
                  :class="debt.balance === 0 ? 'text-gray-500 line-through' : (debt.debtor_id === authUser.id ? 'text-red-400' : 'text-green-400')"
                >
                  {{ formatCurrency(debt.balance) }}
                </p>
              </div>
            </div>

            <!-- Progress Bar -->
            <div v-if="debt.balance > 0" class="mb-3">
              <div class="w-full bg-gray-700 rounded-full h-2">
                <div
                  class="h-2 rounded-full transition-all"
                  :class="debt.debtor_id === authUser.id ? 'bg-red-500' : 'bg-green-500'"
                  :style="{ width: `${(1 - debt.balance / debt.amount) * 100}%` }"
                />
              </div>
              <p class="text-xs text-gray-500 mt-1">
                <span v-if="debt.debtor_id === authUser.id">{{ Math.round((1 - debt.balance / debt.amount) * 100) }}% paid</span>
                <span v-else>{{ Math.round((1 - debt.balance / debt.amount) * 100) }}% collected</span>
              </p>
            </div>

            <!-- Description -->
            <p v-if="debt.description" class="text-xs text-gray-500 mb-3">
              {{ debt.description }}
            </p>

            <!-- Pay or Settled Button -->
            <button
              v-if="debt.balance > 0 && debt.debtor_id === authUser.id"
              @click="openPayModal(debt)"
              class="w-full py-2 px-3 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors"
            >
              Pay
            </button>
            <div v-else-if="debt.balance === 0" class="w-full py-2 px-3 bg-gray-700 text-gray-400 text-sm font-medium rounded-lg text-center">
              ✓ Settled
            </div>
          </div>
        </div>
      </div>

      <!-- Family Debts Section -->
      <div v-if="debts.family_debts && debts.family_debts.length > 0" :class="personalDebts.length > 0 ? 'border-t border-gray-700 pt-6' : ''">
        <h2 class="text-base font-bold text-white mb-1">Family Debts</h2>
        <p class="text-xs text-gray-500 mb-3">(shared with your family)</p>
        <div class="space-y-3">
          <div
            v-for="debt in debts.family_debts"
            :key="debt.id"
            class="relative bg-gray-800 border border-gray-700 rounded-xl p-3"
            :class="{ 'opacity-50': debt.balance === 0 }"
          >
            <!-- Family Badge -->
            <div class="absolute top-3 right-3 flex items-center gap-2">
              <div class="flex items-center justify-center w-6 h-6 rounded bg-purple-900/30 border border-purple-700/50">
                <svg class="w-4 h-4 text-purple-300" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z" />
                </svg>
              </div>
              <!-- Delete Button -->
              <button
                v-if="authUser.id === debt.debtor_id || authUser.can_manage_family"
                @click="toggleDeleteConfirm(debt.id)"
                class="text-gray-500 hover:text-red-400 transition-colors p-1"
                :title="deleteConfirmDebt === debt.id ? 'Confirm delete' : 'Delete debt'"
              >
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
              </button>
            </div>

            <!-- Added By Info -->
            <p class="text-xs text-gray-600 mb-2">Added by: {{ debt.debtor?.name }}</p>

            <!-- Creditor Info -->
            <div class="mb-3 pr-12">
              <p class="text-xs text-gray-500">Owed to</p>
              <p class="text-sm font-medium" :class="debt.creditor_id ? 'text-white' : 'text-gray-300 italic'">
                {{ debt.creditor?.name || debt.creditor_name }}
              </p>
            </div>

            <!-- Amounts -->
            <div class="grid grid-cols-2 gap-3 mb-3">
              <div>
                <p class="text-xs text-gray-500">Original</p>
                <p class="text-sm font-medium text-gray-300">{{ formatCurrency(debt.amount) }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500">Remaining</p>
                <p
                  class="text-sm font-medium"
                  :class="debt.balance === 0 ? 'text-gray-500 line-through' : 'text-blue-400'"
                >
                  {{ formatCurrency(debt.balance) }}
                </p>
              </div>
            </div>

            <!-- Progress Bar -->
            <div v-if="debt.balance > 0" class="mb-3">
              <div class="w-full bg-gray-700 rounded-full h-2">
                <div
                  class="bg-blue-500 h-2 rounded-full transition-all"
                  :style="{ width: `${(1 - debt.balance / debt.amount) * 100}%` }"
                />
              </div>
              <p class="text-xs text-gray-500 mt-1">
                {{ Math.round((1 - debt.balance / debt.amount) * 100) }}% paid
              </p>
            </div>

            <!-- Description -->
            <p v-if="debt.description" class="text-xs text-gray-500 mb-3">
              {{ debt.description }}
            </p>

            <!-- Pay or Settled Button -->
            <button
              v-if="debt.balance > 0"
              @click="openPayModal(debt)"
              class="w-full py-2 px-3 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors"
            >
              Pay
            </button>
            <div v-else class="w-full py-2 px-3 bg-gray-700 text-gray-400 text-sm font-medium rounded-lg text-center">
              ✓ Settled
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-if="personalDebts.length === 0 && (!debts.family_debts || debts.family_debts.length === 0)" class="text-center py-12">
        <p class="text-gray-500 text-sm">No debts yet</p>
      </div>
    </div>

    <!-- Add Debt Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showAddDebtModal" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showAddDebtModal = false" />
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">Add Debt</h2>
            <button @click="showAddDebtModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <!-- Debt Type Toggle -->
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Debt Type</label>
              <div class="flex gap-2">
                <button
                  @click="() => { addDebtForm.is_family_debt = false; }"
                  :class="[
                    'flex-1 py-2 px-3 rounded-lg font-medium transition-colors',
                    addDebtForm.is_family_debt
                      ? 'bg-gray-700 text-gray-300'
                      : 'bg-blue-600 text-white'
                  ]"
                >
                  Personal
                </button>
                <button
                  @click="() => { addDebtForm.is_family_debt = true; addDebtForm.is_interfamily = false; }"
                  :class="[
                    'flex-1 py-2 px-3 rounded-lg font-medium transition-colors',
                    addDebtForm.is_family_debt
                      ? 'bg-blue-600 text-white'
                      : 'bg-gray-700 text-gray-300'
                  ]"
                >
                  Family Shared
                </button>
              </div>
              <p v-if="addDebtForm.is_family_debt" class="text-xs text-gray-400 mt-2">
                This debt will be visible to all family members
              </p>
            </div>

            <!-- Creditor Type Toggle (only for personal debts) -->
            <div v-if="!addDebtForm.is_family_debt">
              <label class="block text-sm font-medium text-gray-300 mb-2">Creditor Type</label>
              <div class="flex gap-2">
                <button
                  @click="addDebtForm.is_interfamily = false"
                  :class="[
                    'flex-1 py-2 px-3 rounded-lg font-medium transition-colors',
                    addDebtForm.is_interfamily
                      ? 'bg-gray-700 text-gray-300'
                      : 'bg-blue-600 text-white'
                  ]"
                >
                  External Party
                </button>
                <button
                  @click="addDebtForm.is_interfamily = true"
                  :class="[
                    'flex-1 py-2 px-3 rounded-lg font-medium transition-colors',
                    addDebtForm.is_interfamily
                      ? 'bg-blue-600 text-white'
                      : 'bg-gray-700 text-gray-300'
                  ]"
                >
                  Family Member
                </button>
              </div>
            </div>

            <!-- Conditional Creditor Field -->
            <div v-if="addDebtForm.is_interfamily">
              <label for="creditor-select" class="block text-sm font-medium text-gray-300 mb-2">
                Family Member
              </label>
              <select
                id="creditor-select"
                v-model="addDebtForm.creditor_id"
                class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
              >
                <option value="" disabled>Select a family member</option>
                <option v-for="user in familyUsers" :key="user.id" :value="user.id">
                  {{ user.name }}
                </option>
              </select>
            </div>
            <div v-else>
              <label for="creditor-name" class="block text-sm font-medium text-gray-300 mb-2">
                Owed to (name)
              </label>
              <input
                id="creditor-name"
                v-model="addDebtForm.creditor_name"
                type="text"
                placeholder="e.g., Bank of America, Dad"
                class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
              />
            </div>

            <!-- Amount Input -->
            <div>
              <label for="add-amount" class="block text-sm font-medium text-gray-300 mb-2">
                Amount
              </label>
              <div class="relative">
                <span class="absolute left-4 top-2 text-gray-400">$</span>
                <input
                  id="add-amount"
                  v-model.number="addDebtForm.amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  placeholder="0.00"
                  class="w-full pl-8 pr-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                />
              </div>
            </div>

            <!-- Description -->
            <textarea
              v-model="addDebtForm.description"
              placeholder="Description (optional)"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500 resize-none"
              rows="3"
            />

            <!-- Error -->
            <div v-if="addDebtError" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
              <p class="text-red-400 text-sm">{{ addDebtError }}</p>
            </div>

            <!-- Buttons -->
            <div class="flex gap-2">
              <button
                @click="showAddDebtModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="submitAddDebt"
                :disabled="addDebtLoading || !isAddDebtFormValid()"
                class="flex-1 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 disabled:bg-gray-700"
              >
                Save
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Pay Debt Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showPayModal && selectedDebt" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showPayModal = false" />
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">
              Pay: {{ selectedDebt.creditor?.name || selectedDebt.creditor_name }}
            </h2>
            <button @click="showPayModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Amount Remaining</label>
              <p class="text-2xl font-bold text-red-400">{{ formatCurrency(selectedDebt.balance) }}</p>
            </div>

            <div>
              <label for="pay-amount" class="block text-sm font-medium text-gray-300 mb-2">
                Amount to Pay
              </label>
              <div class="relative">
                <span class="absolute left-4 top-2 text-gray-400">$</span>
                <input
                  id="pay-amount"
                  v-model.number="payForm.amount"
                  type="number"
                  step="0.01"
                  min="0"
                  :max="selectedDebt.balance"
                  placeholder="0.00"
                  class="w-full pl-8 pr-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                />
              </div>
            </div>

            <textarea
              v-model="payForm.description"
              placeholder="Description (optional)"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500 resize-none"
              rows="3"
            />

            <div v-if="payError" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
              <p class="text-red-400 text-sm">{{ payError }}</p>
            </div>

            <div class="flex gap-2">
              <button
                @click="showPayModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="submitPayment"
                :disabled="submitLoading || !payForm.amount || payForm.amount <= 0"
                class="flex-1 py-2 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 disabled:bg-gray-700"
              >
                Pay
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useApi } from '../composables/useApi';

const { get, post, del, loading, error } = useApi();

const debts = ref({ owed: [], owing: [], family_debts: [] });
const familyUsers = ref([]);
const authUser = ref({});
const showAddDebtModal = ref(false);
const showPayModal = ref(false);
const selectedDebt = ref(null);
const submitLoading = ref(false);
const addDebtLoading = ref(false);
const payError = ref(null);
const addDebtError = ref(null);
const deleteConfirmDebt = ref(null);

const addDebtForm = ref({
  is_family_debt: false,
  is_interfamily: false,
  creditor_id: '',
  creditor_name: '',
  amount: null,
  description: '',
});

const payForm = ref({
  amount: null,
  description: '',
});

const filteredFamilyUsers = computed(() => {
  return familyUsers.value.filter(user => user.id !== authUser.value.id);
});

const personalDebts = computed(() => {
  const all = [...(debts.value.owed || []), ...(debts.value.owing || [])];
  return all.filter(debt => !debt.is_family_debt);
});

onMounted(() => {
  fetchDebts();
  fetchFamilyUsers();
  fetchAuthUser();
});

async function fetchDebts() {
  try {
    const data = await get('/debts');
    debts.value = data;
  } catch (err) {
    console.error('Failed to fetch debts:', err);
  }
}

async function fetchFamilyUsers() {
  try {
    const data = await get('/family/users');
    familyUsers.value = data;
  } catch (err) {
    console.error('Failed to fetch family users:', err);
  }
}

async function fetchAuthUser() {
  try {
    const data = await get('/user');
    authUser.value = data;
  } catch (err) {
    console.error('Failed to fetch auth user:', err);
  }
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
}

function isAddDebtFormValid() {
  if (!addDebtForm.value.amount || addDebtForm.value.amount <= 0) {
    return false;
  }
  if (addDebtForm.value.is_interfamily) {
    return !!addDebtForm.value.creditor_id;
  } else {
    return !!addDebtForm.value.creditor_name;
  }
}

function openPayModal(debt) {
  selectedDebt.value = debt;
  payForm.value = { amount: debt.balance, description: '' };
  payError.value = null;
  showPayModal.value = true;
}

function toggleDeleteConfirm(debtId) {
  if (deleteConfirmDebt.value === debtId) {
    deleteDebt(debtId);
  } else {
    deleteConfirmDebt.value = debtId;
    setTimeout(() => {
      if (deleteConfirmDebt.value === debtId) {
        deleteConfirmDebt.value = null;
      }
    }, 3000);
  }
}

async function deleteDebt(debtId) {
  try {
    await del(`/debts/${debtId}`);
    // Remove from local arrays
    debts.value.owed = debts.value.owed.filter(d => d.id !== debtId);
    debts.value.owing = debts.value.owing.filter(d => d.id !== debtId);
    debts.value.family_debts = debts.value.family_debts.filter(d => d.id !== debtId);
    deleteConfirmDebt.value = null;
  } catch (err) {
    console.error('Failed to delete debt:', err);
    deleteConfirmDebt.value = null;
  }
}

async function submitAddDebt() {
  addDebtError.value = null;
  addDebtLoading.value = true;

  try {
    const payload = {
      is_family_debt: addDebtForm.value.is_family_debt,
      is_interfamily: addDebtForm.value.is_interfamily,
      amount: addDebtForm.value.amount,
      description: addDebtForm.value.description,
    };

    if (addDebtForm.value.is_interfamily) {
      payload.creditor_id = parseInt(addDebtForm.value.creditor_id);
    } else {
      payload.creditor_name = addDebtForm.value.creditor_name;
    }

    await post('/debts', payload);
    showAddDebtModal.value = false;
    addDebtForm.value = {
      is_family_debt: false,
      is_interfamily: false,
      creditor_id: '',
      creditor_name: '',
      amount: null,
      description: '',
    };
    await fetchDebts();
  } catch (err) {
    addDebtError.value = err.response?.data?.message || 'Failed to create debt';
  } finally {
    addDebtLoading.value = false;
  }
}

async function submitPayment() {
  payError.value = null;
  submitLoading.value = true;

  try {
    await post('/debts/pay', {
      debt_id: selectedDebt.value.id,
      amount: payForm.value.amount,
      description: payForm.value.description,
    });
    showPayModal.value = false;
    await fetchDebts();
  } catch (err) {
    payError.value = err.response?.data?.message || 'Failed to process payment';
  } finally {
    submitLoading.value = false;
  }
}
</script>
