<template>
  <div class="pb-32">
    <!-- Header -->
    <div class="sticky top-0 bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-white">Funds</h1>
        <p class="text-gray-400 text-sm mt-1">Manage your savings and allocation rules</p>
      </div>
      <button
        @click="showNewFundForm = true"
        class="inline-flex items-center gap-2 px-3 py-2 sm:px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors p-2"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Add Fund</span>
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading funds...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ error }}</p>
      <button @click="fetchFunds" class="mt-2 text-xs text-red-400 hover:text-red-300 underline">
        Try again
      </button>
    </div>

    <!-- Empty State -->
    <div v-else-if="funds.length === 0" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-12 h-12 text-gray-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m0 0h6m0 0V6m0 0H6m0 0V3" />
        </svg>
        <p class="text-gray-400 font-medium">No funds yet</p>
        <p class="text-gray-500 text-sm">Tap the + button to create your first fund</p>
      </div>
    </div>

    <!-- Funds List -->
    <div v-else class="space-y-3 px-4 py-4">
      <div
        v-for="fund in funds"
        :key="fund.id"
        class="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden"
      >
        <!-- Fund Header -->
        <button
          @click="toggleFundExpand(fund.id)"
          class="w-full p-3 hover:bg-gray-700 transition-colors text-left"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-1">
                <h3 class="text-base font-bold text-white">{{ fund.name }}</h3>
                <span
                  :class="[
                    'text-xs px-2 py-0.5 rounded-full',
                    fund.scope === 'family' || fund.family_id
                      ? 'bg-purple-900/30 border border-purple-700/50 text-purple-300'
                      : 'bg-gray-700 text-gray-400'
                  ]"
                >
                  {{ fund.scope === 'family' || fund.family_id ? 'Family' : 'Personal' }}
                </span>
              </div>
              <p class="text-gray-400 text-sm mt-1">{{ fund.description }}</p>
            </div>
            <div class="text-right flex-shrink-0 flex items-start gap-2">
              <button
                @click.stop="openEditFundModal(fund)"
                class="text-gray-400 hover:text-blue-400 transition-colors"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
              <button
                v-if="deleteConfirmFund !== fund.id"
                @click.stop="confirmDeleteFund(fund)"
                class="text-gray-400 hover:text-red-400 transition-colors"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
              <button
                v-else
                @click.stop="confirmDeleteFund(fund)"
                class="text-red-400 animate-pulse"
              >
                Confirm?
              </button>
              <div class="text-right flex-shrink-0">
                <div class="text-2xl font-bold" :class="fundBalanceClass(fund.balance ?? 0)">
                  {{ formatCurrency(fund.balance ?? 0) }}
                </div>
                <svg
                  class="w-5 h-5 text-gray-400 mt-1 transition-transform"
                  :class="{ 'rotate-180': expandedFunds[fund.id] }"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                </svg>
              </div>
            </div>
          </div>
        </button>

        <!-- Fund Actions -->
        <div v-if="expandedFunds[fund.id]" class="border-t border-gray-700 px-4 py-3 space-y-3">
          <!-- Fund Rules -->
          <div v-if="fund.fund_rules && fund.fund_rules.length > 0" class="space-y-2">
            <h4 class="text-xs font-semibold text-gray-400 uppercase">Allocation Rules</h4>
            <div class="space-y-2">
              <div
                v-for="rule in sortedRules(fund.fund_rules)"
                :key="rule.id"
                class="bg-gray-700/50 rounded-lg p-3 flex items-start justify-between gap-2"
              >
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-blue-400">{{ rule.order }}</span>
                    <span class="text-sm font-medium text-gray-300">{{ rule.name }}</span>
                    <span class="text-xs text-gray-500">
                      {{ rule.allocation_type === 'percentage' ? `${rule.amount}%` : formatCurrency(rule.amount) }}
                    </span>
                  </div>
                  <div class="text-xs text-gray-500 mt-1">
                    {{ rule.allocation_base }} • {{ rule.is_active ? '✓ Active' : 'Inactive' }}
                  </div>
                </div>
                <button
                  @click="openEditRuleModal(rule)"
                  class="text-gray-400 hover:text-blue-400 transition-colors flex-shrink-0"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <div v-else class="text-xs text-gray-500 py-2">
            No allocation rules yet
          </div>

          <!-- Action Buttons -->
          <div class="flex gap-2 pt-2">
            <button
              @click="openBorrowModal(fund)"
              class="flex-1 py-2 px-3 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg transition-colors"
            >
              Borrow
            </button>
            <button
              v-if="(fund.balance ?? 0) > 0"
              @click="openSweepModal(fund)"
              class="flex-1 py-2 px-3 bg-teal-700 hover:bg-teal-600 text-white text-sm font-medium rounded-lg transition-colors"
            >
              Sweep
            </button>
            <button
              @click="openFundHistoryModal(fund)"
              class="flex-1 py-2 px-3 bg-gray-600 hover:bg-gray-500 text-white text-sm font-medium rounded-lg transition-colors"
            >
              History
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- New Fund Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showNewFundForm" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showNewFundForm = false" />
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">New Fund</h2>
            <button @click="showNewFundForm = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <input
              v-model="newFund.name"
              type="text"
              placeholder="Fund name"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            />
            <textarea
              v-model="newFund.description"
              placeholder="Description (optional)"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500 resize-none"
              rows="3"
            />
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">
                Starting Balance <span class="text-gray-500">(optional)</span>
              </label>
              <div class="relative">
                <span class="absolute left-4 top-2 text-gray-400">$</span>
                <input
                  v-model.number="newFund.starting_balance"
                  v-bind="mobileDecimalNumberAttrs"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  class="w-full pl-8 pr-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                />
              </div>
            </div>
            <label v-if="user?.family_id" class="flex items-center gap-3">
              <input
                v-model="newFund.is_family_fund"
                type="checkbox"
                class="w-4 h-4 bg-gray-800 border border-gray-700 rounded focus:ring-blue-500"
              />
              <span class="text-sm text-gray-300">Family Fund (shared with all family members)</span>
            </label>
            <div class="flex gap-2">
              <button
                @click="showNewFundForm = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="createFund"
                :disabled="submitLoading || !newFund.name"
                class="flex-1 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 disabled:bg-gray-700"
              >
                Create
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Edit Fund Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showEditFundModal && editingFund" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showEditFundModal = false" />
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">Edit Fund</h2>
            <button @click="showEditFundModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <input
              v-model="editingFund.name"
              type="text"
              placeholder="Fund name"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            />
            <textarea
              v-model="editingFund.description"
              placeholder="Description (optional)"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500 resize-none"
              rows="3"
            />
            <div class="flex gap-2">
              <button
                @click="showEditFundModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="updateFund"
                :disabled="submitLoading || !editingFund.name"
                class="flex-1 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 disabled:bg-gray-700"
              >
                Save
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Edit Rule Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showEditRuleModal && editingRule" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showEditRuleModal = false" />
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">Edit Rule</h2>
            <button @click="showEditRuleModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <input
              v-model="editingRule.name"
              type="text"
              placeholder="Rule name"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            />
            <input
              v-model.number="editingRule.order"
              v-bind="mobileIntegerNumberAttrs"
              type="number"
              min="1"
              placeholder="Order"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            />
            <select
              v-model="editingRule.allocation_type"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            >
              <option value="percentage">Percentage</option>
              <option value="fixed">Fixed Amount</option>
            </select>
            <input
              v-model.number="editingRule.amount"
              v-bind="mobileDecimalNumberAttrs"
              type="number"
              min="0"
              step="0.01"
              :placeholder="editingRule.allocation_type === 'percentage' ? 'Percentage (0-100)' : 'Amount ($)'"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            />
            <select
              v-if="editingRule.allocation_type === 'percentage'"
              v-model="editingRule.allocation_base"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            >
              <option value="gross_income">Gross Income</option>
              <option value="net_income">Net Income</option>
              <option value="remaining">Remaining</option>
            </select>
            <label class="flex items-center gap-3">
              <input
                v-model="editingRule.is_active"
                type="checkbox"
                class="w-4 h-4 bg-gray-800 border border-gray-700 rounded focus:ring-blue-500"
              />
              <span class="text-sm text-gray-300">Active</span>
            </label>
            <div class="flex gap-2">
              <button
                @click="showEditRuleModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="updateRule"
                :disabled="submitLoading || !editingRule.name || !editingRule.order || !editingRule.amount"
                class="flex-1 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 disabled:bg-gray-700"
              >
                Save
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Borrow Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showBorrowModal && selectedFund" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showBorrowModal = false" />
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">Borrow from {{ selectedFund.name }}</h2>
            <button @click="showBorrowModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">Amount</label>
              <div class="relative">
                <span class="absolute left-4 top-2 text-gray-400">$</span>
                <input
                  v-model.number="borrowForm.amount"
                  v-bind="mobileDecimalNumberAttrs"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  class="w-full pl-8 pr-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                />
              </div>
            </div>
            <textarea
              v-model="borrowForm.description"
              placeholder="Description (optional)"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500 resize-none"
              rows="3"
            />
            <div v-if="borrowError" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
              <p class="text-red-400 text-sm">{{ borrowError }}</p>
            </div>
            <div class="flex gap-2">
              <button
                @click="showBorrowModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="submitBorrow"
                :disabled="submitLoading || !borrowForm.amount || borrowForm.amount <= 0"
                class="flex-1 py-2 bg-amber-600 text-white font-medium rounded-lg hover:bg-amber-700 disabled:bg-gray-700"
              >
                Borrow
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Sweep to Savings Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showSweepModal && selectedFund" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showSweepModal = false" />
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <div>
              <h2 class="text-xl font-bold text-white">Sweep to Savings</h2>
              <p class="text-gray-400 text-sm">{{ selectedFund.name }}</p>
            </div>
            <button @click="showSweepModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <div class="bg-gray-800 rounded-lg p-3 flex items-center justify-between">
              <span class="text-sm text-gray-400">Available Balance</span>
              <span class="text-lg font-bold text-green-400">{{ formatCurrency(selectedFund.balance ?? 0) }}</span>
            </div>
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-medium text-gray-300">Amount to Sweep</label>
                <button
                  @click="sweepForm.amount = selectedFund.balance"
                  class="text-xs text-teal-400 hover:text-teal-300 underline"
                >
                  Sweep All
                </button>
              </div>
              <div class="relative">
                <span class="absolute left-4 top-2 text-gray-400">$</span>
                <input
                  v-model.number="sweepForm.amount"
                  v-bind="mobileDecimalNumberAttrs"
                  type="number"
                  step="0.01"
                  min="0.01"
                  :max="selectedFund.balance"
                  placeholder="0.00"
                  class="w-full pl-8 pr-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-teal-500"
                />
              </div>
            </div>
            <div
              v-if="sweepForm.amount > 0 && sweepForm.amount < (selectedFund.balance ?? 0)"
              class="bg-gray-800 rounded-lg p-3 flex items-center justify-between"
            >
              <span class="text-sm text-gray-400">Remaining in fund</span>
              <span class="text-sm font-semibold text-amber-400">
                {{ formatCurrency((selectedFund.balance ?? 0) - sweepForm.amount) }}
              </span>
            </div>
            <textarea
              v-model="sweepForm.description"
              placeholder="Note (optional)"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-teal-500 resize-none"
              rows="2"
            />
            <div v-if="sweepError" class="p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
              <p class="text-red-400 text-sm">{{ sweepError }}</p>
            </div>
            <div class="flex gap-2">
              <button
                @click="showSweepModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="submitSweep"
                :disabled="submitLoading || !sweepForm.amount || sweepForm.amount <= 0 || sweepForm.amount > (selectedFund.balance ?? 0)"
                class="flex-1 py-2 bg-teal-700 text-white font-medium rounded-lg hover:bg-teal-600 disabled:bg-gray-700"
              >
                {{ submitLoading ? 'Sweeping…' : 'Confirm Sweep' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Fund History Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showFundHistoryModal && selectedFundForHistory" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showFundHistoryModal = false" />
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <div>
              <h2 class="text-xl font-bold text-white">{{ selectedFundForHistory.name }}</h2>
              <p class="text-gray-400 text-sm">Movement History</p>
            </div>
            <button @click="showFundHistoryModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-3">
            <!-- Current balance -->
            <div class="bg-gray-800 rounded-lg p-3 flex items-center justify-between">
              <span class="text-sm text-gray-400">Current Balance</span>
              <span class="text-lg font-bold" :class="fundBalanceClass(selectedFundForHistory.balance ?? 0)">{{ formatCurrency(selectedFundForHistory.balance ?? 0) }}</span>
            </div>
            <!-- Empty state -->
            <div v-if="!selectedFundForHistory.movements || selectedFundForHistory.movements.length === 0" class="py-8 text-center">
              <p class="text-gray-500 text-sm">No movements recorded yet</p>
            </div>
            <!-- Movement list (newest first) -->
            <div v-else class="space-y-2">
              <div
                v-for="movement in [...selectedFundForHistory.movements].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))"
                :key="movement.id"
                class="bg-gray-800 border border-gray-700 rounded-lg p-3"
              >
                <div class="flex items-center justify-between">
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                      <span
                        class="text-xs px-2 py-0.5 rounded-full"
                        :class="{
                          'bg-green-900/30 text-green-400 border border-green-700/50': movement.type === 'allocation' || movement.type === 'closeout_allocation' || movement.type === 'repayment' || movement.type === 'initial_value',
                          'bg-amber-900/30 text-amber-400 border border-amber-700/50': movement.type === 'borrow' || movement.type === 'advance_settlement',
                          'bg-teal-900/30 text-teal-400 border border-teal-700/50': movement.type === 'savings_sweep',
                        }"
                      >
                        {{ movementTypeLabel(movement.type) }}
                      </span>
                    </div>
                    <p v-if="movement.description" class="text-xs text-gray-500 mt-1">{{ movement.description }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                      By {{ movementActorName(movement) }}
                    </p>
                    <p class="text-xs text-gray-600 mt-0.5">
                      {{ new Date(movement.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) }}
                    </p>
                  </div>
                  <p
                    class="text-sm font-bold ml-3"
                    :class="{
                      'text-amber-400': movement.type === 'borrow' || movement.type === 'advance_settlement',
                      'text-teal-400': movement.type === 'savings_sweep',
                      'text-green-400': movement.type !== 'borrow' && movement.type !== 'advance_settlement' && movement.type !== 'savings_sweep',
                    }"
                  >
                    {{ (movement.type === 'borrow' || movement.type === 'advance_settlement' || movement.type === 'savings_sweep') ? '-' : '+' }}{{ formatCurrency(movement.amount) }}
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
import { ref, onMounted } from 'vue';
import { useAuth } from '../composables/useAuth';
import { useApi } from '../composables/useApi';
import { mobileDecimalNumberAttrs, mobileIntegerNumberAttrs } from '../support/mobileNumericInputAttrs.js';

const { user } = useAuth();
const { get, post, put, del, loading, error } = useApi();

const funds = ref([]);
const expandedFunds = ref({});
const showNewFundForm = ref(false);
const showEditFundModal = ref(false);
const showEditRuleModal = ref(false);
const showBorrowModal = ref(false);
const showSweepModal = ref(false);
const showFundHistoryModal = ref(false);
const selectedFund = ref(null);
const selectedFundForHistory = ref(null);
const editingFund = ref(null);
const editingRule = ref(null);
const submitLoading = ref(false);
const borrowError = ref(null);
const sweepError = ref(null);
const deleteConfirmFund = ref(null);

const newFund = ref({
  name: '',
  description: '',
  is_family_fund: false,
  starting_balance: null,
});

const borrowForm = ref({
  amount: null,
  description: '',
});
const sweepForm = ref({
  amount: null,
  description: '',
});

onMounted(() => {
  fetchFunds();
});

async function fetchFunds() {
  try {
    const data = await get('/funds');
    funds.value = data;
  } catch (err) {
    console.error('Failed to fetch funds:', err);
  }
}

function toggleFundExpand(fundId) {
  expandedFunds.value[fundId] = !expandedFunds.value[fundId];
}

function sortedRules(rules) {
  return [...rules].sort((a, b) => a.order - b.order);
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
}

function fundBalanceClass(balance) {
  if (Number(balance) > 0) {
    return 'text-green-400';
  }

  if (Number(balance) < 0) {
    return 'text-red-400';
  }

  return 'text-gray-300';
}

function openEditFundModal(fund) {
  editingFund.value = { ...fund };
  showEditFundModal.value = true;
}

function openEditRuleModal(rule) {
  editingRule.value = { ...rule };
  showEditRuleModal.value = true;
}

function openBorrowModal(fund) {
  selectedFund.value = fund;
  borrowForm.value = { amount: null, description: '' };
  borrowError.value = null;
  showBorrowModal.value = true;
}

function openSweepModal(fund) {
  selectedFund.value = fund;
  sweepForm.value = { amount: null, description: '' };
  sweepError.value = null;
  showSweepModal.value = true;
}

function openFundHistoryModal(fund) {
  selectedFundForHistory.value = fund;
  showFundHistoryModal.value = true;
}

function movementTypeLabel(type) {
  const labels = {
    allocation: 'Allocation',
    closeout_allocation: 'Closeout Allocation',
    borrow: 'Borrow',
    repayment: 'Repayment',
    initial_value: 'Initial Value Set At',
    advance_settlement: 'Advance Settlement',
    savings_sweep: 'Savings Sweep',
  };
  return labels[type] || type;
}

function movementActorName(movement) {
  const authId = user.value?.id;
  if (authId != null && Number(movement.user_id) === Number(authId)) {
    return 'You';
  }
  const u = movement.user;
  if (u && typeof u.name === 'string' && u.name.trim() !== '') {
    return u.name;
  }
  return 'Unknown user';
}

function confirmDeleteFund(fund) {
  if (deleteConfirmFund.value === fund.id) {
    deleteFund(fund);
    deleteConfirmFund.value = null;
  } else {
    deleteConfirmFund.value = fund.id;
    setTimeout(() => {
      deleteConfirmFund.value = null;
    }, 3000);
  }
}

async function createFund() {
  submitLoading.value = true;
  try {
    const fund = await post('/funds', {
      name: newFund.value.name,
      description: newFund.value.description,
      is_family_fund: newFund.value.is_family_fund,
      starting_balance: newFund.value.starting_balance || 0,
    });
    funds.value.push(fund);
    showNewFundForm.value = false;
    newFund.value = { name: '', description: '', is_family_fund: false, starting_balance: null };
  } catch (err) {
    console.error('Failed to create fund:', err);
  } finally {
    submitLoading.value = false;
  }
}

async function updateFund() {
  submitLoading.value = true;
  try {
    const updatedFund = await put(`/funds/${editingFund.value.id}`, {
      name: editingFund.value.name,
      description: editingFund.value.description,
    });
    const index = funds.value.findIndex((f) => f.id === updatedFund.id);
    if (index !== -1) {
      funds.value[index] = updatedFund;
    }
    showEditFundModal.value = false;
    editingFund.value = null;
  } catch (err) {
    console.error('Failed to update fund:', err);
  } finally {
    submitLoading.value = false;
  }
}

async function deleteFund(fund) {
  submitLoading.value = true;
  try {
    await del(`/funds/${fund.id}`);
    funds.value = funds.value.filter((f) => f.id !== fund.id);
  } catch (err) {
    console.error('Failed to delete fund:', err);
  } finally {
    submitLoading.value = false;
  }
}

async function updateRule() {
  submitLoading.value = true;
  try {
    const updatedRule = await put(`/fund-rules/${editingRule.value.id}`, {
      name: editingRule.value.name,
      order: editingRule.value.order,
      allocation_type: editingRule.value.allocation_type,
      amount: editingRule.value.amount,
      allocation_base: editingRule.value.allocation_base,
      is_active: editingRule.value.is_active,
    });
    const fundIndex = funds.value.findIndex((f) => f.id === updatedRule.fund_id);
    if (fundIndex !== -1) {
      const ruleIndex = funds.value[fundIndex].fund_rules.findIndex((r) => r.id === updatedRule.id);
      if (ruleIndex !== -1) {
        funds.value[fundIndex].fund_rules[ruleIndex] = updatedRule;
      }
    }
    showEditRuleModal.value = false;
    editingRule.value = null;
  } catch (err) {
    console.error('Failed to update rule:', err);
  } finally {
    submitLoading.value = false;
  }
}

async function submitBorrow() {
  borrowError.value = null;
  submitLoading.value = true;
  try {
    await post(`/funds/${selectedFund.value.id}/borrow`, {
      amount: borrowForm.value.amount,
      description: borrowForm.value.description,
    });
    showBorrowModal.value = false;
    await fetchFunds();
  } catch (err) {
    borrowError.value = err.response?.data?.message || 'Failed to borrow from fund';
  } finally {
    submitLoading.value = false;
  }
}

async function submitSweep() {
  sweepError.value = null;
  submitLoading.value = true;
  try {
    await post(`/funds/${selectedFund.value.id}/sweep`, {
      amount: sweepForm.value.amount,
      description: sweepForm.value.description,
    });
    showSweepModal.value = false;
    await fetchFunds();
  } catch (err) {
    sweepError.value = err.response?.data?.message || 'Failed to sweep fund';
  } finally {
    submitLoading.value = false;
  }
}
</script>
