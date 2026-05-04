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
                <div class="text-2xl font-bold text-blue-400">
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
              @click="openAddRuleModal(fund)"
              class="flex-1 py-2 px-3 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors"
            >
              + Add Rule
            </button>
            <button
              @click="openBorrowModal(fund)"
              class="flex-1 py-2 px-3 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg transition-colors"
            >
              Borrow
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
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
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
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
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

    <!-- Add Rule Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div v-if="showAddRuleModal && selectedFund" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showAddRuleModal = false" />
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">Add Rule to {{ selectedFund.name }}</h2>
            <button @click="showAddRuleModal = false" class="text-gray-400 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="p-4 space-y-4">
            <input
              v-model="newRule.name"
              type="text"
              placeholder="Rule name"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            />
            <input
              v-model.number="newRule.order"
              type="number"
              min="1"
              placeholder="Order"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            />
            <select
              v-model="newRule.allocation_type"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            >
              <option value="percentage">Percentage</option>
              <option value="fixed">Fixed Amount</option>
            </select>
            <input
              v-model.number="newRule.amount"
              type="number"
              min="0"
              step="0.01"
              :placeholder="newRule.allocation_type === 'percentage' ? 'Percentage (0-100)' : 'Amount ($)'"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            />
            <select
              v-model="newRule.allocation_base"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            >
              <option value="gross_income">Gross Income</option>
              <option value="net_income">Net Income</option>
              <option value="remaining">Remaining</option>
            </select>
            <label class="flex items-center gap-3">
              <input
                v-model="newRule.is_active"
                type="checkbox"
                class="w-4 h-4 bg-gray-800 border border-gray-700 rounded focus:ring-blue-500"
              />
              <span class="text-sm text-gray-300">Active</span>
            </label>
            <div class="flex gap-2">
              <button
                @click="showAddRuleModal = false"
                class="flex-1 py-2 bg-gray-800 text-gray-300 font-medium rounded-lg hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                @click="addRule"
                :disabled="submitLoading || !newRule.name || !newRule.order || !newRule.amount"
                class="flex-1 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 disabled:bg-gray-700"
              >
                Add Rule
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
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
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
              type="number"
              min="0"
              step="0.01"
              :placeholder="editingRule.allocation_type === 'percentage' ? 'Percentage (0-100)' : 'Amount ($)'"
              class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
            />
            <select
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
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
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
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useAuth } from '../composables/useAuth';
import { useApi } from '../composables/useApi';

const { user } = useAuth();
const { get, post, put, del, loading, error } = useApi();

const funds = ref([]);
const expandedFunds = ref({});
const showNewFundForm = ref(false);
const showEditFundModal = ref(false);
const showAddRuleModal = ref(false);
const showEditRuleModal = ref(false);
const showBorrowModal = ref(false);
const selectedFund = ref(null);
const editingFund = ref(null);
const editingRule = ref(null);
const submitLoading = ref(false);
const borrowError = ref(null);
const deleteConfirmFund = ref(null);

const newFund = ref({
  name: '',
  description: '',
  is_family_fund: false,
});

const newRule = ref({
  name: '',
  order: 1,
  allocation_type: 'percentage',
  amount: null,
  allocation_base: 'gross_income',
  is_active: true,
});

const borrowForm = ref({
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

function openAddRuleModal(fund) {
  selectedFund.value = fund;
  showAddRuleModal.value = true;
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
    });
    funds.value.push(fund);
    showNewFundForm.value = false;
    newFund.value = { name: '', description: '', is_family_fund: false };
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

async function addRule() {
  submitLoading.value = true;
  try {
    const rule = await post('/fund-rules', {
      fund_id: selectedFund.value.id,
      name: newRule.value.name,
      order: newRule.value.order,
      allocation_type: newRule.value.allocation_type,
      amount: newRule.value.amount,
      allocation_base: newRule.value.allocation_base,
      is_active: newRule.value.is_active,
    });
    selectedFund.value.fund_rules.push(rule);
    showAddRuleModal.value = false;
    newRule.value = {
      name: '',
      order: 1,
      allocation_type: 'percentage',
      amount: null,
      allocation_base: 'gross_income',
      is_active: true,
    };
  } catch (err) {
    console.error('Failed to add rule:', err);
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
</script>
