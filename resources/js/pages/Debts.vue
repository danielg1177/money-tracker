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
      <!-- All Debts (Personal + Family merged) -->
      <div v-if="personalDebts.length > 0 || (debts.family_debts && debts.family_debts.length > 0)">
        <div class="space-y-3">
          <!-- Personal Debts -->
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
                {{ debt.debtor_id === authUser.id
                  ? (debt.creditor?.name || debt.creditor_name)
                  : debt.debtor?.name }}
              </p>
            </div>

            <!-- Amounts -->
            <div class="grid gap-3 mb-3" :class="debt.creditor_id ? 'grid-cols-1' : 'grid-cols-2'">
              <div v-if="!debt.creditor_id">
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
            <div v-if="debt.balance > 0 && !debt.creditor_id" class="mb-3">
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
            <p v-if="debt.interest_enabled && debt.interest_rate !== null" class="text-xs text-amber-400 mb-3">
              Interest: {{ Number(debt.interest_rate).toFixed(2) }}% APR
            </p>
            <p v-if="debt.loan_received_date" class="text-xs text-gray-500 mb-3">
              Loan received: {{ new Date(debt.loan_received_date).toLocaleDateString('en-US') }}
            </p>

            <!-- Pay / Settled + History + Edit -->
            <div class="flex gap-2">
              <button
                v-if="debt.balance > 0 && debt.debtor_id === authUser.id"
                @click="openPayModal(debt)"
                class="flex-1 py-2 px-3 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors"
              >
                Pay
              </button>
              <div v-else-if="debt.balance === 0" class="flex-1 py-2 px-3 bg-gray-700 text-gray-400 text-sm font-medium rounded-lg text-center">
                ✓ Settled
              </div>
              <button
                v-if="debt.debtor_id === authUser.id"
                @click="openEditDebtModal(debt)"
                class="py-2 px-3 bg-gray-700 hover:bg-gray-600 text-gray-300 text-sm font-medium rounded-lg transition-colors"
              >
                Edit
              </button>
              <button
                @click="openHistoryModal(debt)"
                class="py-2 px-3 bg-gray-700 hover:bg-gray-600 text-gray-300 text-sm font-medium rounded-lg transition-colors"
              >
                History
              </button>
            </div>
          </div>

          <!-- Family Debts -->
          <div
            v-for="debt in debts.family_debts"
            :key="debt.id"
            class="relative bg-gray-800 border border-gray-700 rounded-xl p-3"
            :class="{ 'opacity-50': debt.balance === 0 }"
          >
            <!-- Family Badge -->
            <div class="absolute top-3 right-3 flex items-center gap-2">
              <div class="flex items-center justify-center w-6 h-6 rounded bg-purple-900/30 border border-purple-700/50" title="Family Debt">
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
            <div v-if="debt.balance > 0 && !debt.creditor_id" class="mb-3">
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
            <p v-if="debt.interest_enabled && debt.interest_rate !== null" class="text-xs text-amber-400 mb-3">
              Interest: {{ Number(debt.interest_rate).toFixed(2) }}% APR
            </p>
            <p v-if="debt.loan_received_date" class="text-xs text-gray-500 mb-3">
              Loan received: {{ new Date(debt.loan_received_date).toLocaleDateString('en-US') }}
            </p>

            <!-- Pay / Settled + History + Edit -->
            <div class="flex gap-2">
              <button
                v-if="debt.balance > 0"
                @click="openPayModal(debt)"
                class="flex-1 py-2 px-3 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors"
              >
                Pay
              </button>
              <div v-else class="flex-1 py-2 px-3 bg-gray-700 text-gray-400 text-sm font-medium rounded-lg text-center">
                ✓ Settled
              </div>
              <button
                v-if="authUser.id === debt.debtor_id || authUser.can_manage_family"
                @click="openEditDebtModal(debt)"
                class="py-2 px-3 bg-gray-700 hover:bg-gray-600 text-gray-300 text-sm font-medium rounded-lg transition-colors"
              >
                Edit
              </button>
              <button
                @click="openHistoryModal(debt)"
                class="py-2 px-3 bg-gray-700 hover:bg-gray-600 text-gray-300 text-sm font-medium rounded-lg transition-colors"
              >
                History
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="text-center py-12">
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
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex min-w-0 items-center justify-between">
            <h2 class="text-xl font-bold text-white">Add Debt</h2>
            <button @click="showAddDebtModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4 min-w-0 max-w-full">
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
                  v-bind="mobileDecimalNumberAttrs"
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

            <div class="space-y-3 rounded-lg border border-gray-700 bg-gray-800/40 p-3">
              <div class="flex items-center justify-between">
                <label class="text-sm font-medium text-gray-300">Apply monthly interest at closeout</label>
                <button
                  type="button"
                  @click="addDebtForm.interest_enabled = !addDebtForm.interest_enabled"
                  :class="[
                    'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200',
                    addDebtForm.interest_enabled ? 'bg-amber-600' : 'bg-gray-600'
                  ]"
                >
                  <span
                    :class="[
                      'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200',
                      addDebtForm.interest_enabled ? 'translate-x-5' : 'translate-x-0'
                    ]"
                  />
                </button>
              </div>
              <div v-if="addDebtForm.interest_enabled">
                <label class="block text-sm font-medium text-gray-300 mb-2">Annual Interest Rate (APR %)</label>
                <input
                  v-model.number="addDebtForm.interest_rate"
                  v-bind="mobileDecimalNumberAttrs"
                  type="number"
                  min="0"
                  max="100"
                  step="0.01"
                  class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-amber-500"
                  placeholder="e.g. 12.50"
                />
              </div>
            </div>

            <div class="min-w-0 max-w-full">
              <label class="block text-sm font-medium text-gray-300 mb-2">Loan Received Date (optional)</label>
              <input
                v-model="addDebtForm.loan_received_date"
                type="date"
                class="w-full min-w-0 max-w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
              />
            </div>

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
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex min-w-0 items-center justify-between">
            <h2 class="text-xl font-bold text-white">
              Pay: {{ selectedDebt.creditor?.name || selectedDebt.creditor_name }}
            </h2>
            <button @click="showPayModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4 min-w-0 max-w-full">
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
                  v-bind="mobileDecimalNumberAttrs"
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

            <div class="min-w-0 max-w-full">
              <label class="block text-sm font-medium text-gray-300 mb-2">Payment Date</label>
              <input
                v-model="payForm.transaction_date"
                type="date"
                class="w-full min-w-0 max-w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
              />
            </div>

            <!-- Split toggle -->
            <div class="flex items-center justify-between py-2">
              <label class="text-sm font-medium text-gray-300">Split this payment?</label>
              <button
                type="button"
                @click="payForm.is_split = !payForm.is_split"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200',
                  payForm.is_split ? 'bg-blue-600' : 'bg-gray-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200',
                    payForm.is_split ? 'translate-x-5' : 'translate-x-0'
                  ]"
                />
              </button>
            </div>
            <div v-if="payForm.is_split" class="space-y-3">
              <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Split with</label>
                <select
                  v-model="payForm.split_with_user_id"
                  class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                >
                  <option value="" disabled>Select a family member</option>
                  <option v-for="member in filteredFamilyUsers" :key="member.id" :value="member.id">
                    {{ member.name }}
                  </option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">
                  Their share: {{ payForm.split_percentage }}%
                </label>
                <input
                  v-model.number="payForm.split_percentage"
                  type="range"
                  min="1"
                  max="99"
                  class="w-full accent-blue-500"
                />
                <div class="flex justify-between text-xs text-gray-400 mt-1">
                  <span>You: {{ 100 - payForm.split_percentage }}%</span>
                  <span>Them: {{ payForm.split_percentage }}%</span>
                </div>
              </div>
            </div>

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

    <!-- Payment History Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showHistoryModal && historyDebt" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showHistoryModal = false" />
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex min-w-0 items-center justify-between">
            <h2 class="text-xl font-bold text-white">
              Payment History
            </h2>
            <button @click="showHistoryModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-3 min-w-0 max-w-full">
            <!-- Debt summary line -->
            <p v-if="historyDebt.creditor_id" class="text-sm text-gray-400">
              <span :class="historyDebt.balance === 0 ? 'text-green-400' : 'text-red-400'" class="font-medium">
                {{ formatCurrency(historyDebt.balance) }}
              </span> remaining
            </p>
            <p v-else class="text-sm text-gray-400">
              <span class="text-white font-medium">{{ formatCurrency(historyDebt.amount) }}</span> original,
              <span :class="historyDebt.balance === 0 ? 'text-green-400' : 'text-red-400'" class="font-medium">
                {{ formatCurrency(historyDebt.balance) }}
              </span> remaining
            </p>
            <!-- Loading -->
            <div v-if="historyLoading" class="flex items-center justify-center py-8">
              <svg class="w-6 h-6 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
            </div>
            <!-- Empty -->
            <div v-else-if="debtPayments.length === 0 && (!historyDebt.contributions || historyDebt.contributions.length === 0)" class="py-8 text-center">
              <p class="text-gray-500 text-sm">No history recorded yet</p>
            </div>
            <!-- Payment list -->
            <div v-else class="space-y-2">
              <!-- Closeout contributions -->
              <div v-if="historyDebt.contributions && historyDebt.contributions.length > 0" class="space-y-2">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Closeout Additions</p>
                <div
                  v-for="(contribution, index) in [...(historyDebt.contributions)].reverse()"
                  :key="'contrib-' + index"
                  @click="navigateToMonthSummary(contribution.year, contribution.month)"
                  class="bg-gray-800 border border-amber-700/30 rounded-lg p-3 cursor-pointer hover:bg-gray-750 hover:border-amber-700/50 transition-colors"
                >
                  <div class="flex items-center justify-between gap-3">
                    <div>
                      <p class="text-sm font-medium text-white">
                        {{ monthNames[contribution.month - 1] }} {{ contribution.year }} Closeout
                      </p>
                      <p class="text-xs text-gray-500 mt-0.5">Split settlements added to debt</p>
                    </div>
                    <p class="text-sm font-bold text-amber-400 flex-shrink-0">+{{ formatCurrency(contribution.amount) }}</p>
                  </div>
                </div>
              </div>
              <!-- Manual payments -->
              <div v-if="debtPayments.length > 0" class="space-y-2" :class="{ 'mt-4': historyDebt.contributions && historyDebt.contributions.length > 0 }">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Payments</p>
                <div
                  v-for="(payment, index) in debtPayments"
                  :key="payment.id ?? `${payment.type}-${payment.transaction_date}-${index}`"
                  :class="[
                    'bg-gray-800 border rounded-lg p-3',
                    payment.type === 'initial_value'
                      ? 'border-blue-700/50 bg-blue-900/20'
                      : payment.type === 'interest_accrual'
                        ? 'border-amber-700/50 bg-amber-900/20'
                      : 'border-gray-700'
                  ]"
                >
                  <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2 mb-1">
                        <!-- Initial Value entry label -->
                        <span
                          v-if="payment.type === 'initial_value'"
                          class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-900/30 text-blue-300 border border-blue-700/50"
                        >
                          Initial Value Set At
                        </span>
                        <span
                          v-else-if="payment.type === 'interest_accrual'"
                          class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-900/30 text-amber-300 border border-amber-700/50"
                        >
                          Monthly Interest Accrued
                        </span>
                        <!-- Regular payment description -->
                        <p v-else class="text-sm font-medium text-white">{{ payment.description || 'Debt payment' }}</p>
                        <span
                          v-if="payment.is_closeout_initiated && payment.type !== 'initial_value'"
                          class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-900/30 text-purple-300 border border-purple-700/50"
                          title="Payment was initiated from month closeout"
                        >
                          Closeout
                        </span>
                      </div>
                      <p class="text-xs text-gray-500">
                        {{ new Date(payment.transaction_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) }}
                      </p>
                      <p v-if="payment.paid_by_user && payment.type !== 'initial_value'" class="text-xs text-gray-600 mt-1">
                        {{ payment.type === 'income' ? 'From:' : 'Paid by:' }}
                        <span class="text-gray-300 font-medium">{{ payment.paid_by_user.name }}</span>
                      </p>
                      <div
                        v-if="payment.split_breakdown && payment.split_breakdown.length > 0"
                        class="mt-1 space-y-0.5"
                      >
                        <p
                          v-for="(split, splitIndex) in payment.split_breakdown"
                          :key="`${payment.id ?? 'row'}-split-${split.user_id ?? splitIndex}`"
                          class="text-xs text-gray-500"
                        >
                          {{ getSplitParticipantLabel(split.user_id, split.user_name) }} paid
                          <span class="text-gray-300 font-medium">{{ formatCurrency(split.amount) }}</span>
                          ({{ Number(split.share_percentage).toFixed(2) }}%)
                        </p>
                      </div>
                    </div>
                    <p
                      class="text-sm font-bold ml-3 flex-shrink-0"
                      :class="
                        payment.type === 'initial_value'
                          ? 'text-blue-400'
                          : payment.type === 'interest_accrual'
                            ? 'text-amber-400'
                          : (payment.type === 'income' ? 'text-green-400' : 'text-red-400')
                      "
                    >
                      {{ payment.type === 'income' || payment.type === 'interest_accrual' ? '+' : '' }}{{ formatCurrency(payment.amount) }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Edit Debt Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showEditDebtModal && editingDebt" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showEditDebtModal = false" />
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex min-w-0 items-center justify-between">
            <h2 class="text-xl font-bold text-white">Edit Debt</h2>
            <button @click="showEditDebtModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4 min-w-0 max-w-full">
            <div v-if="!editingDebt.creditor_id">
              <label class="block text-sm font-medium text-gray-300 mb-2">Owed to (name)</label>
              <input
                v-model="editDebtForm.creditor_name"
                type="text"
                placeholder="e.g., Bank of America, Dad"
                class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
              />
            </div>
            <div v-else class="p-3 bg-gray-800 rounded-lg">
              <p class="text-xs text-gray-500">Creditor</p>
              <p class="text-sm text-white font-medium">{{ editingDebt.creditor?.name }}</p>
              <p class="text-xs text-gray-600 mt-1">Family member creditors cannot be changed</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Description</label>
              <textarea
                v-model="editDebtForm.description"
                placeholder="Description (optional)"
                class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500 resize-none"
                rows="3"
              />
            </div>
            <div class="space-y-3 rounded-lg border border-gray-700 bg-gray-800/40 p-3">
              <div class="flex items-center justify-between">
                <label class="text-sm font-medium text-gray-300">Apply monthly interest at closeout</label>
                <button
                  type="button"
                  @click="editDebtForm.interest_enabled = !editDebtForm.interest_enabled"
                  :class="[
                    'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200',
                    editDebtForm.interest_enabled ? 'bg-amber-600' : 'bg-gray-600'
                  ]"
                >
                  <span
                    :class="[
                      'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200',
                      editDebtForm.interest_enabled ? 'translate-x-5' : 'translate-x-0'
                    ]"
                  />
                </button>
              </div>
              <div v-if="editDebtForm.interest_enabled">
                <label class="block text-sm font-medium text-gray-300 mb-2">Annual Interest Rate (APR %)</label>
                <input
                  v-model.number="editDebtForm.interest_rate"
                  v-bind="mobileDecimalNumberAttrs"
                  type="number"
                  min="0"
                  max="100"
                  step="0.01"
                  class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-amber-500"
                  placeholder="e.g. 12.50"
                />
              </div>
            </div>
            <div class="min-w-0 max-w-full">
              <label class="block text-sm font-medium text-gray-300 mb-2">Loan Received Date (optional)</label>
              <input
                v-model="editDebtForm.loan_received_date"
                type="date"
                class="w-full min-w-0 max-w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
              />
            </div>
            <div v-if="editDebtError" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
              <p class="text-red-400 text-sm">{{ editDebtError }}</p>
            </div>
            <div class="flex gap-2">
              <button
                @click="showEditDebtModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="submitEditDebt"
                :disabled="editDebtLoading"
                class="flex-1 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 disabled:bg-gray-700"
              >
                Save
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
import { useRouter } from 'vue-router';
import { useApi } from '../composables/useApi';
import { useAuth } from '../composables/useAuth';
import { mobileDecimalNumberAttrs } from '../support/mobileNumericInputAttrs.js';

const { get, post, put, del, loading, error } = useApi();

const { user: authUser } = useAuth();

const router = useRouter();

const debts = ref({ owed: [], owing: [], family_debts: [] });
const familyUsers = ref([]);
const showAddDebtModal = ref(false);
const showPayModal = ref(false);
const selectedDebt = ref(null);
const submitLoading = ref(false);
const addDebtLoading = ref(false);
const payError = ref(null);
const addDebtError = ref(null);
const deleteConfirmDebt = ref(null);
const showHistoryModal = ref(false);
const historyDebt = ref(null);
const debtPayments = ref([]);
const historyLoading = ref(false);
const showEditDebtModal = ref(false);
const editingDebt = ref(null);
const editDebtForm = ref({
  description: '',
  creditor_name: '',
  interest_enabled: false,
  interest_rate: 0,
  loan_received_date: '',
});
const editDebtLoading = ref(false);
const editDebtError = ref(null);

const monthNames = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

const addDebtForm = ref({
  is_family_debt: false,
  is_interfamily: false,
  creditor_id: '',
  creditor_name: '',
  amount: null,
  description: '',
  interest_enabled: false,
  interest_rate: 0,
  loan_received_date: '',
});

const payForm = ref({
  amount: null,
  description: '',
  transaction_date: '',
  is_split: false,
  split_with_user_id: '',
  split_percentage: 50,
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

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
}

function todayDateString() {
  return new Date().toISOString().slice(0, 10);
}

function getSplitParticipantLabel(userId, userName) {
  return userId === authUser.value.id ? 'You' : userName;
}

function isAddDebtFormValid() {
  if (!addDebtForm.value.amount || addDebtForm.value.amount <= 0) {
    return false;
  }

  if (addDebtForm.value.is_interfamily) {
    if (!addDebtForm.value.creditor_id) {
      return false;
    }
  } else if (!addDebtForm.value.creditor_name) {
    return false;
  }

  if (addDebtForm.value.interest_enabled) {
    const rate = Number(addDebtForm.value.interest_rate);

    return Number.isFinite(rate) && rate >= 0 && rate <= 100;
  }

  return true;
}

function openPayModal(debt) {
  selectedDebt.value = debt;
  payForm.value = {
    amount: debt.balance,
    description: '',
    transaction_date: todayDateString(),
    is_split: false,
    split_with_user_id: '',
    split_percentage: 50,
  };
  payError.value = null;
  showPayModal.value = true;
}

async function openHistoryModal(debt) {
  historyDebt.value = debt;
  debtPayments.value = [];
  historyLoading.value = true;
  showHistoryModal.value = true;
  try {
    const data = await get(`/debts/${debt.id}/payments`);
    debtPayments.value = data;
  } catch (err) {
    console.error('Failed to fetch payment history:', err);
  } finally {
    historyLoading.value = false;
  }
}

function navigateToMonthSummary(year, month) {
  const monthParam = `${year}-${String(month).padStart(2, '0')}`;
  router.push(`/transactions?month=${monthParam}`);
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
      interest_enabled: addDebtForm.value.interest_enabled,
      interest_rate: addDebtForm.value.interest_enabled ? addDebtForm.value.interest_rate : null,
      loan_received_date: addDebtForm.value.loan_received_date || null,
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
      interest_enabled: false,
      interest_rate: 0,
      loan_received_date: '',
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
    const payload = {
      debt_id: selectedDebt.value.id,
      amount: payForm.value.amount,
      description: payForm.value.description,
      transaction_date: payForm.value.transaction_date || todayDateString(),
    };
    if (payForm.value.is_split && payForm.value.split_with_user_id) {
      payload.split_with_user_id = payForm.value.split_with_user_id;
      payload.split_percentage = payForm.value.split_percentage;
    }
    await post('/debts/pay', payload);
    showPayModal.value = false;
    await fetchDebts();
  } catch (err) {
    payError.value = err.response?.data?.message || 'Failed to process payment';
  } finally {
    submitLoading.value = false;
  }
}

function openEditDebtModal(debt) {
  editingDebt.value = debt;
  editDebtForm.value = {
    description: debt.description || '',
    creditor_name: debt.creditor_name || '',
    interest_enabled: !!debt.interest_enabled,
    interest_rate: debt.interest_rate !== null ? Number(debt.interest_rate) : 0,
    loan_received_date: debt.loan_received_date || '',
  };
  editDebtError.value = null;
  showEditDebtModal.value = true;
}

async function submitEditDebt() {
  editDebtError.value = null;
  editDebtLoading.value = true;

  try {
    await put(`/debts/${editingDebt.value.id}`, {
      description: editDebtForm.value.description,
      creditor_name: editDebtForm.value.creditor_name,
      interest_enabled: editDebtForm.value.interest_enabled,
      interest_rate: editDebtForm.value.interest_enabled ? editDebtForm.value.interest_rate : null,
      loan_received_date: editDebtForm.value.loan_received_date || null,
    });
    showEditDebtModal.value = false;
    await fetchDebts();
  } catch (err) {
    editDebtError.value = err.response?.data?.message || 'Failed to update debt';
  } finally {
    editDebtLoading.value = false;
  }
}
</script>
