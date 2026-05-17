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
        aria-label="To Review"
        :aria-selected="activeTab === 'review'"
        class="flex min-h-[44px] min-w-0 flex-1 items-center justify-center gap-1.5 rounded-lg px-1 py-2 text-xs font-semibold transition-colors"
        :class="
          activeTab === 'review'
            ? 'bg-gray-800 text-white shadow-sm'
            : 'text-gray-400 hover:text-gray-200'
        "
        @click="activeTab = 'review'"
      >
        <span>Review</span>
        <span
          class="inline-flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-gray-700 px-1.5 text-xs font-bold text-white"
        >
          {{ pendingImports.length }}
        </span>
      </button>
      <button
        type="button"
        role="tab"
        aria-label="Transfers"
        :aria-selected="activeTab === 'transfers'"
        class="flex min-h-[44px] min-w-0 flex-1 items-center justify-center gap-1.5 rounded-lg px-1 py-2 text-xs font-semibold transition-colors"
        :class="
          activeTab === 'transfers'
            ? 'bg-gray-800 text-white shadow-sm'
            : 'text-gray-400 hover:text-gray-200'
        "
        @click="activeTab = 'transfers'"
      >
        <span>Transfers</span>
        <span
          class="inline-flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-amber-900/80 px-1.5 text-xs font-bold text-amber-100 ring-1 ring-amber-700/50"
        >
          {{ transferImports.length }}
        </span>
      </button>
      <button
        type="button"
        role="tab"
        aria-label="Auto-Created"
        :aria-selected="activeTab === 'auto-created'"
        class="flex min-h-[44px] min-w-0 flex-1 items-center justify-center gap-1.5 rounded-lg px-1 py-2 text-xs font-semibold transition-colors"
        :class="
          activeTab === 'auto-created'
            ? 'bg-gray-800 text-white shadow-sm'
            : 'text-gray-400 hover:text-gray-200'
        "
        @click="activeTab = 'auto-created'"
      >
        <span>Auto</span>
        <span
          class="inline-flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-emerald-900/80 px-1.5 text-xs font-bold text-emerald-100 ring-1 ring-emerald-700/50"
        >
          {{ autoCreatedImports.length }}
        </span>
      </button>
      <button
        type="button"
        role="tab"
        aria-label="Ignored"
        :aria-selected="activeTab === 'ignored'"
        class="flex min-h-[44px] min-w-0 flex-1 items-center justify-center gap-1.5 rounded-lg px-1 py-2 text-xs font-semibold transition-colors"
        :class="
          activeTab === 'ignored'
            ? 'bg-gray-800 text-white shadow-sm'
            : 'text-gray-400 hover:text-gray-200'
        "
        @click="activeTab = 'ignored'"
      >
        <span>Ignored</span>
        <span
          v-if="dismissedImports.length > 0"
          class="inline-flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-gray-700 px-1.5 text-xs font-bold text-gray-300 ring-1 ring-gray-600/50"
        >
          {{ dismissedImports.length }}
        </span>
      </button>
    </div>

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
                <div v-if="row.suggested_category || hasConfidence(row) || institutionName(row)" class="mt-2 flex flex-wrap items-center gap-2">
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
                  <span
                    v-if="institutionName(row)"
                    class="inline-flex rounded-full border border-blue-800/40 bg-blue-950/30 px-2.5 py-0.5 text-xs text-blue-300"
                  >
                    {{ institutionName(row) }}
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
              v-if="row.raw_payload?.suggested_repayment_group"
              class="mx-4 mb-2 rounded-md border border-cyan-700/30 bg-cyan-950/10 px-3 py-2 text-xs text-cyan-300"
            >
              This looks like a repayment — {{ row.raw_payload.suggested_repayment_group.mirror_transaction_ids.length }} linked expense(s) totalling {{ formatCurrency(row.raw_payload.suggested_repayment_group.total) }} were found. Consider splitting this import to link to those expenses.
            </div>

            <div
              v-show="expandedId === row.id"
              class="space-y-3 border-t border-gray-700/80 px-4 pb-4 pt-3"
            >
              <button
                type="button"
                class="flex w-full items-center justify-between rounded-lg border px-3 py-2.5 text-sm font-medium transition-colors"
                :class="isSplitMode(row)
                  ? 'border-indigo-600/60 bg-indigo-900/30 text-indigo-200'
                  : 'border-gray-600 bg-gray-800/60 text-gray-300 hover:border-gray-500 hover:text-white'"
                :disabled="actionId === row.id"
                @click="toggleSplitMode(row)"
              >
                <span>Split into multiple transactions</span>
                <span class="text-xs" :class="isSplitMode(row) ? 'text-indigo-300' : 'text-gray-500'">
                  {{ isSplitMode(row) ? 'Cancel split' : 'One charge, multiple categories?' }}
                </span>
              </button>

              <template v-if="!isSplitMode(row)">
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
                  @change="onCategoryChange(row)"
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

              <div
                v-if="formFor(row).type === 'income'"
                class="space-y-3 rounded-lg border border-gray-700 bg-gray-900/40 p-3"
              >
                <div>
                  <p class="text-sm font-medium text-gray-300">Income from taking debt?</p>
                  <p class="mt-0.5 text-xs text-gray-500">Optional — attach to an existing loan or record new debt</p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                  <button
                    type="button"
                    class="min-h-[40px] rounded-lg px-2 py-2 text-xs font-medium transition-colors"
                    :class="
                      formFor(row).income_debt_mode === 'none'
                        ? 'bg-gray-600 text-white'
                        : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    "
                    :disabled="actionId === row.id"
                    @click="setIncomeDebtMode(row, 'none')"
                  >
                    No
                  </button>
                  <button
                    type="button"
                    class="min-h-[40px] rounded-lg px-2 py-2 text-xs font-medium transition-colors"
                    :class="
                      formFor(row).income_debt_mode === 'existing'
                        ? 'bg-sky-600 text-white'
                        : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    "
                    :disabled="actionId === row.id"
                    @click="setIncomeDebtMode(row, 'existing')"
                  >
                    Existing
                  </button>
                  <button
                    type="button"
                    class="min-h-[40px] rounded-lg px-2 py-2 text-xs font-medium transition-colors"
                    :class="
                      formFor(row).income_debt_mode === 'new'
                        ? 'bg-emerald-600 text-white'
                        : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                    "
                    :disabled="actionId === row.id"
                    @click="setIncomeDebtMode(row, 'new')"
                  >
                    New
                  </button>
                </div>
                <div v-if="formFor(row).income_debt_mode === 'existing'" class="space-y-1">
                  <label class="block text-xs font-medium text-gray-400" :for="`inc-debt-${row.id}`">Attach to debt</label>
                  <select
                    :id="`inc-debt-${row.id}`"
                    v-model.number="formFor(row).income_existing_debt_id"
                    class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-sky-500 focus:outline-none disabled:opacity-50"
                    :disabled="actionId === row.id"
                  >
                    <option :value="null" disabled>Select a debt</option>
                    <option v-for="d in incomeAttachableDebts" :key="d.id" :value="d.id">
                      {{ incomeDebtSelectLabel(d) }} — {{ formatCurrency(Number(d.balance) || 0) }}
                    </option>
                  </select>
                  <p v-if="incomeAttachableDebts.length === 0" class="text-xs text-amber-400">
                    No debts available to attach.
                  </p>
                </div>
                <div v-if="formFor(row).income_debt_mode === 'new'" class="space-y-3">
                  <div class="grid grid-cols-2 gap-2">
                    <button
                      type="button"
                      class="min-h-[40px] rounded-lg px-3 py-2 text-xs font-medium transition-colors"
                      :class="
                        !formFor(row).income_new_is_interfamily
                          ? 'bg-blue-600 text-white'
                          : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                      "
                      :disabled="actionId === row.id"
                      @click="formFor(row).income_new_is_interfamily = false"
                    >
                      External
                    </button>
                    <button
                      type="button"
                      class="min-h-[40px] rounded-lg px-3 py-2 text-xs font-medium transition-colors"
                      :class="
                        formFor(row).income_new_is_interfamily
                          ? 'bg-blue-600 text-white'
                          : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                      "
                      :disabled="actionId === row.id"
                      @click="formFor(row).income_new_is_interfamily = true"
                    >
                      Family
                    </button>
                  </div>
                  <div v-if="formFor(row).income_new_is_interfamily">
                    <label class="mb-1 block text-xs font-medium text-gray-400">Family creditor</label>
                    <select
                      v-model.number="formFor(row).income_new_creditor_id"
                      class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none disabled:opacity-50"
                      :disabled="actionId === row.id"
                    >
                      <option :value="null" disabled>Select member</option>
                      <option v-for="member in familyUsers" :key="member.id" :value="member.id">
                        {{ member.name }}
                      </option>
                    </select>
                  </div>
                  <div v-else>
                    <label class="mb-1 block text-xs font-medium text-gray-400">Creditor name</label>
                    <input
                      v-model="formFor(row).income_new_creditor_name"
                      type="text"
                      placeholder="e.g., Bank"
                      class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none disabled:opacity-50"
                      :disabled="actionId === row.id"
                    />
                  </div>
                  <label class="flex items-center gap-2 text-xs text-gray-300">
                    <input
                      v-model="formFor(row).income_new_is_family_debt"
                      type="checkbox"
                      class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600"
                      :disabled="actionId === row.id"
                    />
                    Visible to all family members
                  </label>
                  <div>
                    <label class="mb-1 block text-xs font-medium text-gray-400">Debt note (optional)</label>
                    <input
                      v-model="formFor(row).income_new_description"
                      type="text"
                      class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none disabled:opacity-50"
                      :disabled="actionId === row.id"
                    />
                  </div>
                  <div class="space-y-2 rounded-lg border border-gray-700 bg-gray-900/30 p-3">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-xs font-medium text-gray-300">Monthly interest at closeout</span>
                      <button
                        type="button"
                        class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200"
                        :class="formFor(row).income_new_interest_enabled ? 'bg-amber-600' : 'bg-gray-600'"
                        :disabled="actionId === row.id"
                        @click="formFor(row).income_new_interest_enabled = !formFor(row).income_new_interest_enabled"
                      >
                        <span
                          class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow transition duration-200"
                          :class="formFor(row).income_new_interest_enabled ? 'translate-x-4' : 'translate-x-0'"
                        />
                      </button>
                    </div>
                    <div v-if="formFor(row).income_new_interest_enabled">
                      <label class="mb-1 block text-xs font-medium text-gray-400">APR %</label>
                      <input
                        v-model.number="formFor(row).income_new_interest_rate"
                        v-bind="mobileDecimalNumberAttrs"
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-amber-500 focus:outline-none disabled:opacity-50"
                        :disabled="actionId === row.id"
                      />
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="formFor(row).type === 'expense'" class="space-y-2">
                <div
                  class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-900/40 p-3 transition-colors hover:border-gray-600"
                  role="button"
                  tabindex="0"
                  @click="actionId !== row.id && togglePayTowardDebt(row)"
                  @keydown.enter.prevent="actionId !== row.id && togglePayTowardDebt(row)"
                  @keydown.space.prevent="actionId !== row.id && togglePayTowardDebt(row)"
                >
                  <div>
                    <p class="text-sm font-medium text-gray-300">Pay toward a tracked debt</p>
                    <p class="mt-0.5 text-xs text-gray-500">Links this expense to a debt</p>
                  </div>
                  <div
                    class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
                    :class="formFor(row).pay_toward_debt ? 'bg-sky-600' : 'bg-gray-700'"
                  >
                    <div
                      class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
                      :class="formFor(row).pay_toward_debt ? 'translate-x-5' : 'translate-x-1'"
                    />
                  </div>
                </div>
                <div v-if="formFor(row).pay_toward_debt" class="space-y-1">
                  <label class="block text-xs font-medium text-gray-400" :for="`debt-${row.id}`">Which debt?</label>
                  <select
                    :id="`debt-${row.id}`"
                    v-model.number="formFor(row).debt_id"
                    class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-sky-500 focus:outline-none disabled:opacity-50"
                    :disabled="actionId === row.id"
                  >
                    <option :value="null" disabled>Select a debt</option>
                    <option v-for="d in payableDebts" :key="d.id" :value="d.id">
                      {{ debtSelectLabel(d) }} — {{ formatCurrency(Number(d.balance) || 0) }} left
                    </option>
                  </select>
                  <p v-if="payableDebts.length === 0" class="text-xs text-amber-400">No payable debts found.</p>
                </div>
              </div>

              <div
                v-if="formFor(row).type === 'expense'"
                class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-900/40 p-3 transition-colors hover:border-gray-600"
                role="button"
                tabindex="0"
                @click="actionId !== row.id && toggleSplit(row)"
                @keydown.enter.prevent="actionId !== row.id && toggleSplit(row)"
                @keydown.space.prevent="actionId !== row.id && toggleSplit(row)"
              >
                <div>
                  <p class="text-sm font-medium text-gray-300">Split between family members</p>
                  <p class="mt-0.5 text-xs text-gray-500">Divide this expense</p>
                </div>
                <div
                  class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
                  :class="formFor(row).is_split ? 'bg-blue-600' : 'bg-gray-700'"
                >
                  <div
                    class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
                    :class="formFor(row).is_split ? 'translate-x-5' : 'translate-x-1'"
                  />
                </div>
              </div>

              <div v-if="formFor(row).type === 'expense' && formFor(row).is_split">
                <SplitEditor
                  :family-users="familyUsers"
                  :total-amount="importAbsAmount(row)"
                  :initial-splits="formFor(row).split_data"
                  @update:splits="formFor(row).split_data = $event"
                />
              </div>

              <div v-if="formFor(row).type === 'expense' && !formFor(row).pay_toward_debt" class="space-y-2">
                <div
                  class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-900/40 p-3 transition-colors hover:border-gray-600"
                  role="button"
                  tabindex="0"
                  @click="actionId !== row.id && toggleAdvanceFund(row)"
                  @keydown.enter.prevent="actionId !== row.id && toggleAdvanceFund(row)"
                  @keydown.space.prevent="actionId !== row.id && toggleAdvanceFund(row)"
                >
                  <div>
                    <p class="text-sm font-medium text-gray-300">Advance against fund</p>
                    <p class="mt-0.5 text-xs text-gray-500">Deduct from a fund at month close</p>
                  </div>
                  <div
                    class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
                    :class="formFor(row).advance_fund_id !== null ? 'bg-amber-600' : 'bg-gray-700'"
                  >
                    <div
                      class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
                      :class="formFor(row).advance_fund_id !== null ? 'translate-x-5' : 'translate-x-1'"
                    />
                  </div>
                </div>
                <select
                  v-if="formFor(row).advance_fund_id !== null"
                  v-model.number="formFor(row).advance_fund_id"
                  class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-amber-500 focus:outline-none disabled:opacity-50"
                  :disabled="actionId === row.id"
                  @change="onAdvanceFundChange(row)"
                >
                  <option :value="null" disabled>Select a fund</option>
                  <option v-for="fund in funds" :key="fund.id" :value="fund.id">
                    {{ fund.name }}{{ fund.scope === 'family' ? ' (family)' : '' }}
                  </option>
                </select>
                <div
                  v-if="formFor(row).advance_fund_id !== null && selectedFundHasNonNecessityRule(formFor(row))"
                  class="flex cursor-pointer items-center justify-between rounded-lg border border-gray-700 bg-gray-900/40 p-3 transition-colors hover:border-gray-600"
                  role="button"
                  tabindex="0"
                  @click="actionId !== row.id && (formFor(row).is_non_necessity = !formFor(row).is_non_necessity)"
                  @keydown.enter.prevent="actionId !== row.id && (formFor(row).is_non_necessity = !formFor(row).is_non_necessity)"
                  @keydown.space.prevent="actionId !== row.id && (formFor(row).is_non_necessity = !formFor(row).is_non_necessity)"
                >
                  <div>
                    <p class="text-sm font-medium text-gray-300">Mark as non-necessity</p>
                    <p class="mt-0.5 text-xs text-gray-500">Excluded from expense basis when the fund allows it</p>
                  </div>
                  <div
                    class="relative flex h-6 w-10 shrink-0 rounded-full transition-colors"
                    :class="formFor(row).is_non_necessity ? 'bg-violet-600' : 'bg-gray-700'"
                  >
                    <div
                      class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition-transform"
                      :class="formFor(row).is_non_necessity ? 'translate-x-5' : 'translate-x-1'"
                    />
                  </div>
                </div>
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
              </template>

              <template v-if="isSplitMode(row)">
                <div class="rounded-lg border border-gray-700/60 bg-gray-900/40 px-3 py-2 text-xs text-gray-400">
                  <span>Bank charge:</span>
                  <span class="ml-1 font-semibold tabular-nums text-white">{{ formatCurrency(importAbsAmount(row)) }}</span>
                  <span class="mx-2 text-gray-600">·</span>
                  <span :class="Math.abs(splitRemaining(row)) <= 0.01 ? 'text-emerald-400' : 'text-amber-400'">
                    {{ Math.abs(splitRemaining(row)) <= 0.01 ? 'Fully allocated' : `${formatCurrency(Math.abs(splitRemaining(row)))} ${splitRemaining(row) > 0 ? 'remaining' : 'over'}` }}
                  </span>
                </div>

                <div
                  v-for="(line, idx) in splitModes[row.id]?.lines ?? []"
                  :key="idx"
                  class="rounded-lg border border-gray-700 bg-gray-900/30 p-3 space-y-2.5"
                >
                  <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-400">Line {{ idx + 1 }}</span>
                    <button
                      v-if="(splitModes[row.id]?.lines?.length ?? 0) > 2"
                      type="button"
                      class="text-xs text-red-400 hover:text-red-300 disabled:opacity-50"
                      :disabled="actionId === row.id"
                      @click="removeSplitLine(row, idx)"
                    >
                      Remove
                    </button>
                  </div>

                  <div>
                    <label class="mb-1 block text-xs font-medium text-gray-400">Amount</label>
                    <input
                      v-model="line.amount"
                      type="text"
                      inputmode="decimal"
                      placeholder="0.00"
                      class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white placeholder-gray-600 focus:border-indigo-500 focus:outline-none disabled:opacity-50"
                      :disabled="actionId === row.id"
                    />
                  </div>

                  <div>
                    <label class="mb-1 block text-xs font-medium text-gray-400">Type</label>
                    <div class="grid grid-cols-2 gap-2">
                      <button
                        type="button"
                        class="min-h-[44px] rounded-lg py-2 text-sm font-medium transition-colors disabled:opacity-50"
                        :class="line.type === 'expense' ? 'bg-red-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                        :disabled="actionId === row.id"
                        @click="setSplitLineType(line, 'expense')"
                      >Expense</button>
                      <button
                        type="button"
                        class="min-h-[44px] rounded-lg py-2 text-sm font-medium transition-colors disabled:opacity-50"
                        :class="line.type === 'income' ? 'bg-emerald-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                        :disabled="actionId === row.id"
                        @click="setSplitLineType(line, 'income')"
                      >Income</button>
                    </div>
                  </div>

                  <div>
                    <label class="mb-1 block text-xs font-medium text-gray-400">Category</label>
                    <select
                      v-model="line.category_id"
                      class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none disabled:opacity-50"
                      :disabled="actionId === row.id"
                      @change="applySplitLineCategoryDefaults(line)"
                    >
                      <option value="" disabled>Select category</option>
                      <option
                        v-for="cat in categoriesForType(line.type)"
                        :key="cat.id"
                        :value="String(cat.id)"
                      >
                        {{ cat.icon ? cat.icon + ' ' : '' }}{{ cat.name }}
                      </option>
                    </select>
                  </div>

                  <div>
                    <label class="mb-1 block text-xs font-medium text-gray-400">Description <span class="text-gray-600">(optional)</span></label>
                    <input
                      v-model="line.description"
                      type="text"
                      placeholder="What was this for?"
                      class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white placeholder-gray-600 focus:border-indigo-500 focus:outline-none disabled:opacity-50"
                      :disabled="actionId === row.id"
                    />
                  </div>

                  <PlaidImportSplitLineOptions
                    :line="line"
                    :disabled="actionId === row.id"
                    :categories="categories"
                    :funds="funds"
                    :family-users="familyUsers"
                    :payable-debts="payableDebts"
                    :income-attachable-debts="incomeAttachableDebts"
                  />
                </div>

                <button
                  type="button"
                  class="flex min-h-[44px] w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-gray-600 bg-transparent py-2 text-sm text-gray-400 transition-colors hover:border-gray-500 hover:text-gray-300 disabled:opacity-50"
                  :disabled="actionId === row.id"
                  @click="addSplitLine(row)"
                >
                  + Add another line
                </button>

                <p v-if="rowErrors[row.id]" class="text-sm text-red-300">
                  {{ rowErrors[row.id] }}
                </p>

                <div class="flex flex-col gap-2 sm:flex-row-reverse sm:justify-end">
                  <button
                    type="button"
                    class="min-h-[48px] w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto sm:min-w-[10rem]"
                    :disabled="actionId === row.id || !splitLinesValid(row)"
                    @click="confirmSplitRow(row)"
                  >
                    {{ actionId === row.id ? 'Saving…' : 'Confirm Split' }}
                  </button>
                  <button
                    type="button"
                    class="min-h-[48px] w-full rounded-xl border border-gray-600 bg-transparent px-4 py-3 text-sm font-semibold text-gray-200 transition-colors hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto sm:min-w-[8rem]"
                    :disabled="actionId === row.id"
                    @click="toggleSplitMode(row)"
                  >
                    Cancel
                  </button>
                </div>
              </template>
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

      <div v-show="activeTab === 'auto-created'">
        <p class="mb-3 text-sm text-gray-400 leading-relaxed">
          These transactions were created automatically based on your past history. Approve them if correct, or correct them to improve future accuracy.
          <span class="mt-1 block text-gray-500">Tap a row to see the full ledger transaction (splits, funds, debt flags, Plaid link).</span>
        </p>
        <p v-if="autoCreatedImports.length === 0" class="text-sm text-gray-500">
          No auto-created transactions to review.
        </p>
        <ul v-else class="space-y-3">
          <li
            v-for="row in autoCreatedImports"
            :key="'ac-' + row.id"
            class="overflow-hidden rounded-xl border border-gray-700 bg-gray-800/80"
          >
            <div class="px-4 py-3">
              <button
                type="button"
                class="flex w-full min-h-[48px] items-start gap-3 text-left transition-colors hover:bg-gray-800/40 -mx-1 rounded-lg px-1 py-0.5"
                :disabled="actionId === row.id"
                @click="toggleAutoCreatedExpand(row)"
              >
                <div class="min-w-0 flex-1">
                  <p class="font-bold text-white truncate">
                    {{ row.merchant_name || row.raw_name || 'Transaction' }}
                  </p>
                  <p class="mt-1 text-sm text-gray-400">
                    {{ formatDate(row.date) }}
                    <span class="mx-1.5 text-gray-600">·</span>
                    <span :class="(row.transaction?.type ?? row.suggested_type) === 'income' ? 'text-emerald-400' : 'text-red-400'">
                      {{ (row.transaction?.type ?? row.suggested_type) === 'income' ? '+' : '−' }}{{ formatMoney(row.amount) }}
                    </span>
                  </p>
                  <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span
                      v-if="row.transaction?.category"
                      class="inline-flex max-w-full items-center gap-1 truncate rounded-full border border-emerald-700/50 bg-emerald-950/40 px-2.5 py-0.5 text-xs text-emerald-200"
                    >
                      <span v-if="row.transaction.category.icon" class="shrink-0">{{ row.transaction.category.icon }}</span>
                      <span class="truncate">{{ row.transaction.category.name }}</span>
                    </span>
                    <span
                      v-if="institutionName(row)"
                      class="inline-flex rounded-full border border-gray-600 bg-gray-900/60 px-2.5 py-0.5 text-xs text-gray-400"
                    >
                      {{ institutionName(row) }}
                    </span>
                    <span
                      v-if="row.confidence_score"
                      class="inline-flex rounded-full border border-gray-600 bg-gray-900/60 px-2.5 py-0.5 text-xs text-gray-400"
                    >
                      {{ formatConfidence(row.confidence_score) }} confidence
                    </span>
                  </div>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1.5 pt-0.5">
                  <span class="inline-flex items-center rounded-full bg-emerald-900/40 px-2 py-0.5 text-xs font-medium text-emerald-300 ring-1 ring-emerald-700/50">
                    Auto
                  </span>
                  <span class="text-gray-500" aria-hidden="true">
                    <svg
                      class="h-5 w-5 transition-transform"
                      :class="{ 'rotate-180': expandedAutoCreatedId === row.id }"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                  </span>
                </div>
              </button>

              <div
                v-show="expandedAutoCreatedId === row.id"
                class="mt-3 space-y-3 border-t border-gray-700/80 pt-3"
              >
                <template v-if="row.transaction">
                  <p class="text-xs font-semibold text-gray-300">On your books</p>
                  <dl class="space-y-2.5 text-xs text-gray-300">
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Ledger ID</dt>
                      <dd class="min-w-0 break-all text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        #{{ row.transaction.id }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Type</dt>
                      <dd class="min-w-0 text-right capitalize text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ row.transaction.type }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Ledger date</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ formatLedgerDate(row.transaction) }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Ledger amount</dt>
                      <dd
                        class="min-w-0 text-right font-medium tabular-nums sm:max-w-[60%] sm:text-right"
                        :class="row.transaction.type === 'income' ? 'text-emerald-400' : 'text-red-400'"
                      >
                        {{ row.transaction.type === 'income' ? '+' : '−' }}{{ formatMoney(row.transaction.amount) }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Description</dt>
                      <dd class="min-w-0 break-words text-right text-gray-200 sm:max-w-[70%] sm:text-right">
                        {{ row.transaction.description || '—' }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Recorded by</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ row.transaction.user?.name || '—' }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Category</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        <template v-if="row.transaction.category">
                          <span v-if="row.transaction.category.icon">{{ row.transaction.category.icon }} </span>{{ row.transaction.category.name }}
                        </template>
                        <template v-else>—</template>
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Split expense</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ yesNoDisplay(row.transaction.is_split) }}
                      </dd>
                    </div>
                    <div v-if="row.transaction.is_split && row.transaction.splits?.length" class="rounded-lg border border-gray-700/80 bg-gray-900/40 p-2">
                      <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-gray-500">Split shares</p>
                      <ul class="space-y-1.5">
                        <li
                          v-for="sp in row.transaction.splits"
                          :key="sp.id"
                          class="flex justify-between gap-2 text-xs text-gray-200"
                        >
                          <span class="min-w-0 truncate">{{ sp.user?.name || `User #${sp.user_id}` }}</span>
                          <span class="shrink-0 tabular-nums text-gray-400">{{ sp.share_percentage }}% · {{ formatCurrency(Number(sp.amount) || 0) }}</span>
                        </li>
                      </ul>
                    </div>
                    <div v-else-if="splitDataJson(row.transaction)" class="rounded-lg border border-gray-700/80 bg-gray-900/40 p-2">
                      <p class="mb-1 text-[11px] font-medium uppercase tracking-wide text-gray-500">Split template (stored)</p>
                      <pre class="max-h-24 overflow-auto whitespace-pre-wrap break-all text-[11px] text-gray-400">{{ splitDataJson(row.transaction) }}</pre>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Fund (tag)</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ tagFundRecord(row.transaction)?.name || '—' }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Advance fund</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ advanceFundRecord(row.transaction)?.name || '—' }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Non-necessity</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ yesNoDisplay(row.transaction.is_non_necessity) }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Borrow (income)</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ yesNoDisplay(row.transaction.is_borrow) }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Debt payment</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ yesNoDisplay(row.transaction.is_debt_payment) }}
                      </dd>
                    </div>
                    <div v-if="row.transaction.debt_id" class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Linked debt</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ autoCreatedDebtSummary(row.transaction) }} <span class="text-gray-500">(#{{ row.transaction.debt_id }})</span>
                      </dd>
                    </div>
                    <div v-if="row.transaction.paid_by_user_id" class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Paid by</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ row.transaction.paid_by_user?.name || `User #${row.transaction.paid_by_user_id}` }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Closeout-generated</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ yesNoDisplay(row.transaction.is_closeout_initiated) }}
                      </dd>
                    </div>
                    <div v-if="row.transaction.mirror_transaction_id" class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Mirror transaction</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        #{{ row.transaction.mirror_transaction_id }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Plaid transaction ID</dt>
                      <dd class="min-w-0 break-all text-right font-mono text-[11px] text-gray-400 sm:max-w-[70%] sm:text-right">
                        {{ row.transaction.plaid_transaction_id || '—' }}
                      </dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4">
                      <dt class="shrink-0 text-gray-500">Import source</dt>
                      <dd class="min-w-0 text-right text-gray-200 sm:max-w-[60%] sm:text-right">
                        {{ row.transaction.import_source || '—' }}
                      </dd>
                    </div>
                  </dl>
                </template>
                <p v-else class="text-xs text-amber-200/90">No linked ledger transaction.</p>
              </div>

              <!-- Actions -->
              <div v-if="!autoCreatedFormFor(row).correcting" class="mt-3 flex flex-col gap-2 sm:flex-row">
                <button
                  type="button"
                  class="min-h-[44px] flex-1 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-emerald-500 disabled:opacity-50"
                  :disabled="actionId === row.id"
                  @click="approveAutoCreated(row)"
                >
                  {{ actionId === row.id ? 'Saving…' : '✓ Looks Correct' }}
                </button>
                <button
                  type="button"
                  class="min-h-[44px] flex-1 rounded-xl border border-gray-600 bg-transparent px-4 py-2.5 text-sm font-semibold text-gray-200 transition-colors hover:bg-gray-800 disabled:opacity-50"
                  :disabled="actionId === row.id"
                  @click="openAutoCreatedCorrect(row)"
                >
                  Correct It
                </button>
              </div>
              <!-- Correction form -->
              <div v-else class="mt-3 space-y-3 border-t border-gray-700/80 pt-3">
                <div>
                  <label class="mb-1.5 block text-xs font-medium text-gray-400">Type</label>
                  <div class="grid grid-cols-2 gap-2">
                    <button
                      type="button"
                      class="min-h-[44px] rounded-lg py-2.5 text-sm font-medium transition-colors"
                      :class="autoCreatedFormFor(row).type === 'expense' ? 'bg-red-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                      @click="autoCreatedFormFor(row).type = 'expense'"
                    >
                      Expense
                    </button>
                    <button
                      type="button"
                      class="min-h-[44px] rounded-lg py-2.5 text-sm font-medium transition-colors"
                      :class="autoCreatedFormFor(row).type === 'income' ? 'bg-emerald-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                      @click="autoCreatedFormFor(row).type = 'income'"
                    >
                      Income
                    </button>
                  </div>
                </div>
                <div>
                  <label class="mb-1.5 block text-xs font-medium text-gray-400" :for="`ac-cat-${row.id}`">Category</label>
                  <select
                    :id="`ac-cat-${row.id}`"
                    v-model="autoCreatedFormFor(row).category_id"
                    class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  >
                    <option disabled value="">Select a category</option>
                    <option
                      v-for="cat in categoriesForType(autoCreatedFormFor(row).type)"
                      :key="cat.id"
                      :value="String(cat.id)"
                    >
                      {{ cat.icon ? `${cat.icon} ` : '' }}{{ cat.name }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="mb-1.5 block text-xs font-medium text-gray-400" :for="`ac-fund-${row.id}`">Fund (optional)</label>
                  <select
                    :id="`ac-fund-${row.id}`"
                    v-model="autoCreatedFormFor(row).fund_id"
                    class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none"
                  >
                    <option value="">None</option>
                    <option v-for="fund in funds" :key="fund.id" :value="String(fund.id)">
                      {{ fund.name }}{{ fund.scope === 'family' ? ' (family)' : '' }}
                    </option>
                  </select>
                </div>
                <p v-if="rowErrors[row.id]" class="text-sm text-red-300">{{ rowErrors[row.id] }}</p>
                <div class="flex flex-col gap-2 sm:flex-row-reverse sm:justify-end">
                  <button
                    type="button"
                    class="min-h-[48px] w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-500 disabled:opacity-50 sm:w-auto sm:min-w-[10rem]"
                    :disabled="actionId === row.id || !autoCreatedFormFor(row).category_id"
                    @click="submitAutoCreatedCorrection(row)"
                  >
                    {{ actionId === row.id ? 'Saving…' : 'Save Correction' }}
                  </button>
                  <button
                    type="button"
                    class="min-h-[48px] w-full rounded-xl border border-gray-600 bg-transparent px-4 py-3 text-sm font-semibold text-gray-300 transition-colors hover:bg-gray-800 sm:w-auto"
                    :disabled="actionId === row.id"
                    @click="autoCreatedFormFor(row).correcting = false"
                  >
                    Cancel
                  </button>
                </div>
              </div>
            </div>
          </li>
        </ul>
      </div>

      <div v-show="activeTab === 'ignored'">
        <p class="mb-3 text-sm text-gray-400 leading-relaxed">
          These transactions were automatically skipped based on rules you've set. Confirm they're correct, or restore them if the app made a mistake.
        </p>
        <p v-if="dismissedImports.length === 0" class="text-sm text-gray-500">
          No auto-ignored transactions to review.
        </p>
        <ul v-else class="space-y-3">
          <li
            v-for="row in dismissedImports"
            :key="'di-' + row.id"
            class="overflow-hidden rounded-xl border border-gray-700 bg-gray-800/80"
          >
            <div class="px-4 py-3">
              <div class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                  <p class="font-bold text-white truncate">
                    {{ row.merchant_name || row.raw_name || 'Transaction' }}
                  </p>
                  <p class="mt-1 text-sm text-gray-400">
                    {{ formatDate(row.date) }}
                    <span class="mx-1.5 text-gray-600">·</span>
                    <span :class="row.suggested_type === 'income' ? 'text-emerald-400' : 'text-red-400'">
                      {{ row.suggested_type === 'income' ? '+' : '−' }}{{ formatMoney(row.amount) }}
                    </span>
                  </p>
                  <p class="mt-1.5 text-xs text-gray-500">
                    <span v-if="formatPlaidCategoryLabel(row)">{{ formatPlaidCategoryLabel(row) }}</span>
                    <template v-if="institutionName(row)">
                      <span class="mx-1.5 text-gray-600">·</span>
                      <span>{{ institutionName(row) }}</span>
                    </template>
                  </p>
                </div>
                <span class="inline-flex items-center rounded-full bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-400 shrink-0 mt-0.5">
                  Ignored
                </span>
              </div>
              <!-- Actions when not restoring -->
              <div v-if="!dismissedFormFor(row).restoring" class="mt-3 flex flex-col gap-2 sm:flex-row">
                <button
                  type="button"
                  class="min-h-[44px] flex-1 rounded-xl border border-gray-600 bg-transparent px-4 py-2.5 text-sm font-semibold text-gray-200 transition-colors hover:bg-gray-800 disabled:opacity-50"
                  :disabled="actionId === row.id"
                  @click="acknowledgeAutoDismiss(row)"
                >
                  {{ actionId === row.id ? 'Working…' : 'Correct to Ignore' }}
                </button>
                <button
                  type="button"
                  class="min-h-[44px] flex-1 rounded-xl border border-amber-700/60 bg-amber-950/20 px-4 py-2.5 text-sm font-semibold text-amber-200 transition-colors hover:bg-amber-950/40 disabled:opacity-50"
                  :disabled="actionId === row.id"
                  @click="() => { ensureDismissedForm(row); dismissedFormFor(row).restoring = true; }"
                >
                  Shouldn't Be Ignored
                </button>
              </div>
              <!-- Restore form -->
              <div v-else class="mt-3 space-y-3 border-t border-gray-700/80 pt-3">
                <p class="text-xs text-gray-400">
                  Categorize this transaction. The rule will be updated so future transactions from this merchant appear for review instead of being ignored.
                </p>
                <div>
                  <label class="mb-1.5 block text-xs font-medium text-gray-400">Type</label>
                  <div class="grid grid-cols-2 gap-2">
                    <button
                      type="button"
                      class="min-h-[44px] rounded-lg py-2.5 text-sm font-medium transition-colors"
                      :class="dismissedFormFor(row).type === 'expense' ? 'bg-red-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                      @click="dismissedFormFor(row).type = 'expense'"
                    >
                      Expense
                    </button>
                    <button
                      type="button"
                      class="min-h-[44px] rounded-lg py-2.5 text-sm font-medium transition-colors"
                      :class="dismissedFormFor(row).type === 'income' ? 'bg-emerald-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                      @click="dismissedFormFor(row).type = 'income'"
                    >
                      Income
                    </button>
                  </div>
                </div>
                <div>
                  <label class="mb-1.5 block text-xs font-medium text-gray-400" :for="`di-cat-${row.id}`">Category</label>
                  <select
                    :id="`di-cat-${row.id}`"
                    v-model="dismissedFormFor(row).category_id"
                    class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  >
                    <option disabled value="">Select a category</option>
                    <option
                      v-for="cat in categoriesForType(dismissedFormFor(row).type)"
                      :key="cat.id"
                      :value="String(cat.id)"
                    >
                      {{ cat.icon ? `${cat.icon} ` : '' }}{{ cat.name }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="mb-1.5 block text-xs font-medium text-gray-400" :for="`di-fund-${row.id}`">Fund (optional)</label>
                  <select
                    :id="`di-fund-${row.id}`"
                    v-model="dismissedFormFor(row).fund_id"
                    class="min-h-[44px] w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none"
                  >
                    <option value="">None</option>
                    <option v-for="fund in funds" :key="fund.id" :value="String(fund.id)">
                      {{ fund.name }}{{ fund.scope === 'family' ? ' (family)' : '' }}
                    </option>
                  </select>
                </div>
                <p v-if="rowErrors[row.id]" class="text-sm text-red-300">{{ rowErrors[row.id] }}</p>
                <div class="flex flex-col gap-2 sm:flex-row-reverse sm:justify-end">
                  <button
                    type="button"
                    class="min-h-[48px] w-full rounded-xl bg-amber-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-amber-500 disabled:opacity-50 sm:w-auto sm:min-w-[10rem]"
                    :disabled="actionId === row.id || !dismissedFormFor(row).category_id"
                    @click="restoreFromDismiss(row)"
                  >
                    {{ actionId === row.id ? 'Creating…' : 'Create Transaction' }}
                  </button>
                  <button
                    type="button"
                    class="min-h-[48px] w-full rounded-xl border border-gray-600 bg-transparent px-4 py-3 text-sm font-semibold text-gray-300 transition-colors hover:bg-gray-800 sm:w-auto"
                    :disabled="actionId === row.id"
                    @click="dismissedFormFor(row).restoring = false"
                  >
                    Cancel
                  </button>
                </div>
              </div>
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
import { mobileDecimalNumberAttrs } from '../support/mobileNumericInputAttrs.js';
import SplitEditor from '../components/SplitEditor.vue';
import PlaidImportSplitLineOptions from '../components/PlaidImportSplitLineOptions.vue';
import {
  equalSplitPayloadForFamilyUsers,
  hasPositiveSplitShares,
} from '../support/equalFamilySplit.js';

const { post } = useApi();

const loading = ref(true);
const pageError = ref('');
const pendingImports = ref([]);
const transferImports = ref([]);
const activeTab = ref('review');
const categories = ref([]);
const funds = ref([]);
const debtsPayload = ref({ owed: [], owing: [], family_debts: [] });
const familyUsers = ref([]);
const autoCreatedImports = ref([]);
const autoCreatedForms = reactive({});
const dismissedImports = ref([]);
const dismissedForms = reactive({});
const expandedId = ref(null);
const expandedAutoCreatedId = ref(null);
const forms = reactive({});
const splitModes = reactive({});
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
  () =>
    !loading.value &&
    !pageError.value &&
    pendingImports.value.length === 0 &&
    transferImports.value.length === 0 &&
    autoCreatedImports.value.length === 0 &&
    dismissedImports.value.length === 0,
);

const showTabs = computed(
  () =>
    !loading.value &&
    !pageError.value &&
    (pendingImports.value.length > 0 ||
      transferImports.value.length > 0 ||
      autoCreatedImports.value.length > 0 ||
      dismissedImports.value.length > 0),
);

const payableDebts = computed(() => {
  const list = [...(debtsPayload.value?.owed || []), ...(debtsPayload.value?.family_debts || [])];

  return list.filter((d) => !d.is_pending_closeout && Number(d.balance) > 0);
});

const incomeAttachableDebts = computed(() => {
  const list = debtsPayload.value?.owed || [];

  return list.filter((d) => !d.is_pending_closeout && Number(d.balance) >= 0);
});

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

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
}

function advanceFundRecord(tx) {
  if (!tx || typeof tx !== 'object') {
    return null;
  }
  return tx.advance_fund ?? tx.advanceFund ?? null;
}

function tagFundRecord(tx) {
  if (!tx || typeof tx !== 'object') {
    return null;
  }
  return tx.fund ?? null;
}

function yesNoDisplay(val) {
  return val ? 'Yes' : 'No';
}

function autoCreatedDebtSummary(tx) {
  const d = tx?.debt;
  if (!d) {
    return '';
  }
  if (d.creditor?.name) {
    return `To ${d.creditor.name}`;
  }
  if (d.creditor_name) {
    return `To ${d.creditor_name}`;
  }
  if (d.fund?.name) {
    return `Borrowed: ${d.fund.name}`;
  }
  if (d.description) {
    return d.description;
  }
  return `Debt #${d.id}`;
}

function formatLedgerDate(tx) {
  const v = tx?.transaction_date;
  if (!v) {
    return '';
  }
  return formatDate(v);
}

function splitDataJson(tx) {
  const raw = tx?.split_data;
  if (raw == null) {
    return '';
  }
  if (Array.isArray(raw) && raw.length === 0) {
    return '';
  }
  if (typeof raw === 'object' && !Array.isArray(raw) && Object.keys(raw).length === 0) {
    return '';
  }
  try {
    return JSON.stringify(raw);
  } catch {
    return String(raw);
  }
}

function toggleAutoCreatedExpand(row) {
  if (expandedAutoCreatedId.value === row.id) {
    expandedAutoCreatedId.value = null;
  } else {
    expandedAutoCreatedId.value = row.id;
  }
}

function openAutoCreatedCorrect(row) {
  ensureAutoCreatedForm(row);
  autoCreatedFormFor(row).correcting = true;
  expandedAutoCreatedId.value = row.id;
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

function debtSelectLabel(d) {
  if (d.creditor?.name) {
    return `To ${d.creditor.name}`;
  }
  if (d.creditor_name) {
    return `To ${d.creditor_name}`;
  }
  if (d.fund?.name) {
    return `Borrowed: ${d.fund.name}`;
  }
  if (d.description) {
    return d.description;
  }
  return `Debt #${d.id}`;
}

function incomeDebtSelectLabel(d) {
  if (d.creditor?.name) {
    return `Owed to ${d.creditor.name}`;
  }
  if (d.creditor_name) {
    return `Owed to ${d.creditor_name}`;
  }
  if (d.description) {
    return d.description;
  }
  return `Debt #${d.id}`;
}

function importAbsAmount(row) {
  return Math.abs(Number(row.amount) || 0);
}

function selectedFundHasNonNecessityRule(f) {
  if (f.advance_fund_id === null || f.advance_fund_id === undefined) {
    return false;
  }
  const fund = funds.value.find((x) => Number(x.id) === Number(f.advance_fund_id));

  return fund?.has_non_necessity_rule === true;
}

function resetIncomeDebtFields(f) {
  f.income_debt_mode = 'none';
  f.income_existing_debt_id = null;
  f.income_new_is_family_debt = false;
  f.income_new_is_interfamily = false;
  f.income_new_creditor_id = null;
  f.income_new_creditor_name = '';
  f.income_new_description = '';
  f.income_new_interest_enabled = false;
  f.income_new_interest_rate = 0;
}

function resetExpenseOnlyFields(f) {
  f.pay_toward_debt = false;
  f.debt_id = null;
  f.is_split = false;
  f.split_data = [];
  f.advance_fund_id = null;
  f.is_non_necessity = false;
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
    pay_toward_debt: false,
    debt_id: null,
    is_split: false,
    split_data: [],
    advance_fund_id: null,
    is_non_necessity: false,
    income_debt_mode: 'none',
    income_existing_debt_id: null,
    income_new_is_family_debt: false,
    income_new_is_interfamily: false,
    income_new_creditor_id: null,
    income_new_creditor_name: '',
    income_new_description: '',
    income_new_interest_enabled: false,
    income_new_interest_rate: 0,
  };
  applyCategoryDefaults(row, { mergePlaidSuggestion: true });
}

function formFor(row) {
  ensureForm(row);

  return forms[row.id];
}

/**
 * Align expense split / advance / non-necessity with category + per-user defaults from GET /categories.
 * @param {{ mergePlaidSuggestion?: boolean }} options When true (first expand of a row), apply suggested_advance_fund_id / suggested_fund_id / suggested_is_non_necessity only if the category does not define an advance.
 */
function applyCategoryDefaults(row, { mergePlaidSuggestion = false } = {}) {
  const f = forms[row.id];
  if (!f || f.type !== 'expense' || f.pay_toward_debt) {
    return;
  }
  const cat = categories.value.find((c) => String(c.id) === String(f.category_id));
  if (!cat) {
    return;
  }

  if (cat.is_split_default && Array.isArray(cat.split_default) && cat.split_default.length > 0) {
    f.is_split = true;
    f.split_data = familyUsers.value.length ? equalSplitPayloadForFamilyUsers(familyUsers.value) : [];
  } else {
    f.is_split = false;
    f.split_data = [];
  }

  if (cat.advance_fund_id) {
    f.advance_fund_id = Number(cat.advance_fund_id);
  } else if (!mergePlaidSuggestion) {
    f.advance_fund_id = null;
  }

  if (cat.is_non_necessity_default && selectedFundHasNonNecessityRule(f)) {
    f.is_non_necessity = true;
  } else {
    f.is_non_necessity = false;
  }

  if (mergePlaidSuggestion) {
    if (!f.advance_fund_id) {
      const sug = row.suggested_advance_fund_id ?? row.suggested_fund_id;
      if (sug != null && sug !== '') {
        f.advance_fund_id = Number(sug);
      }
    }
    if (row.suggested_is_non_necessity && selectedFundHasNonNecessityRule(f)) {
      f.is_non_necessity = true;
    }
  }
}

function onCategoryChange(row) {
  applyCategoryDefaults(row);
}

function setIncomeDebtMode(row, mode) {
  const f = formFor(row);
  f.income_debt_mode = mode;
  if (mode === 'existing') {
    f.income_new_is_family_debt = false;
    f.income_new_is_interfamily = false;
    f.income_new_creditor_id = null;
    f.income_new_creditor_name = '';
    f.income_new_description = '';
    f.income_new_interest_enabled = false;
    f.income_new_interest_rate = 0;
    if (incomeAttachableDebts.value.length === 1) {
      f.income_existing_debt_id = incomeAttachableDebts.value[0].id;
    } else {
      f.income_existing_debt_id = null;
    }
    return;
  }
  f.income_existing_debt_id = null;
  if (mode !== 'new') {
    resetIncomeDebtFields(f);
    f.income_debt_mode = 'none';
  }
}

function togglePayTowardDebt(row) {
  const f = formFor(row);
  f.pay_toward_debt = !f.pay_toward_debt;
  if (f.pay_toward_debt) {
    f.advance_fund_id = null;
    f.is_non_necessity = false;
    const pd = payableDebts.value;
    if (pd.length === 1) {
      f.debt_id = pd[0].id;
    } else if (!pd.some((d) => Number(d.id) === Number(f.debt_id))) {
      f.debt_id = null;
    }
  } else {
    f.debt_id = null;
    applyCategoryDefaults(row);
  }
}

function toggleSplit(row) {
  const f = formFor(row);
  f.is_split = !f.is_split;
  if (!f.is_split) {
    f.split_data = [];
    f.is_non_necessity = false;

    return;
  }
  if (!familyUsers.value.length || !hasPositiveSplitShares(f.split_data)) {
    f.split_data = equalSplitPayloadForFamilyUsers(familyUsers.value);
  }
}

function toggleAdvanceFund(row) {
  const f = formFor(row);
  if (f.advance_fund_id !== null) {
    f.advance_fund_id = null;
    f.is_non_necessity = false;
  } else {
    f.advance_fund_id = funds.value.length > 0 ? funds.value[0].id : null;
  }
}

function onAdvanceFundChange(row) {
  const f = formFor(row);
  if (f.advance_fund_id === null) {
    f.is_non_necessity = false;
  } else if (!selectedFundHasNonNecessityRule(f)) {
    f.is_non_necessity = false;
  }
}

function setType(row, type) {
  const f = formFor(row);
  f.type = type;
  const pool = categories.value.filter((c) => (type === 'income' ? c.is_income : c.is_expense));
  if (!pool.some((c) => String(c.id) === String(f.category_id))) {
    f.category_id = pool[0] ? String(pool[0].id) : '';
  }
  if (type === 'income') {
    resetExpenseOnlyFields(f);
    resetIncomeDebtFields(f);
  } else {
    resetIncomeDebtFields(f);
    f.pay_toward_debt = false;
    f.debt_id = null;
    applyCategoryDefaults(row);
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
  delete splitModes[id];
  if (expandedId.value === id) {
    expandedId.value = null;
  }
}

function isSplitMode(row) {
  return splitModes[row.id]?.active === true;
}

function makeSplitLine(amount = '') {
  return {
    amount: amount === '' ? '' : String(amount),
    type: 'expense',
    category_id: '',
    description: '',
    pay_toward_debt: false,
    debt_id: null,
    is_split: false,
    split_data: [],
    advance_fund_id: null,
    is_non_necessity: false,
    income_debt_mode: 'none',
    income_existing_debt_id: null,
    income_new_is_family_debt: false,
    income_new_is_interfamily: false,
    income_new_creditor_id: null,
    income_new_creditor_name: '',
    income_new_description: '',
    income_new_interest_enabled: false,
    income_new_interest_rate: 0,
    is_repayment_mode: false,
    repayment_for_user_id: null,
    repayment_links: [],
  };
}

function toggleSplitMode(row) {
  if (splitModes[row.id]?.active) {
    splitModes[row.id] = { active: false, lines: [] };
  } else {
    const total = importAbsAmount(row);
    splitModes[row.id] = {
      active: true,
      lines: [makeSplitLine(total), makeSplitLine()],
    };
  }
}

function addSplitLine(row) {
  if (!splitModes[row.id]) {
    return;
  }
  splitModes[row.id].lines.push(makeSplitLine());
}

function removeSplitLine(row, idx) {
  if (!splitModes[row.id] || splitModes[row.id].lines.length <= 2) {
    return;
  }
  splitModes[row.id].lines.splice(idx, 1);
}

function applySplitLineCategoryDefaults(line) {
  if (!line || line.type !== 'expense' || line.pay_toward_debt) {
    return;
  }
  const cat = categories.value.find((c) => String(c.id) === String(line.category_id));
  if (!cat) {
    return;
  }
  if (cat.is_split_default && Array.isArray(cat.split_default) && cat.split_default.length > 0) {
    line.is_split = true;
    line.split_data = familyUsers.value.length ? equalSplitPayloadForFamilyUsers(familyUsers.value) : [];
  } else {
    line.is_split = false;
    line.split_data = [];
  }
  if (cat.advance_fund_id) {
    line.advance_fund_id = Number(cat.advance_fund_id);
  } else {
    line.advance_fund_id = null;
    line.is_non_necessity = false;
  }
  if (cat.is_non_necessity_default && splitLineFundHasNonNecessityRule(line)) {
    line.is_non_necessity = true;
  } else if (!splitLineFundHasNonNecessityRule(line)) {
    line.is_non_necessity = false;
  }
}

function splitLineFundHasNonNecessityRule(line) {
  if (line.advance_fund_id === null || line.advance_fund_id === undefined) {
    return false;
  }
  const fund = funds.value.find((x) => Number(x.id) === Number(line.advance_fund_id));

  return fund?.has_non_necessity_rule === true;
}

function setSplitLineType(line, type) {
  line.type = type;
  const pool = categories.value.filter((c) => (type === 'income' ? c.is_income : c.is_expense));
  if (!pool.some((c) => String(c.id) === String(line.category_id))) {
    line.category_id = pool[0] ? String(pool[0].id) : '';
  }
  if (type === 'income') {
    line.pay_toward_debt = false;
    line.debt_id = null;
    line.is_split = false;
    line.split_data = [];
    line.advance_fund_id = null;
    line.is_non_necessity = false;
    line.income_debt_mode = 'none';
    line.income_existing_debt_id = null;
    line.income_new_is_family_debt = false;
    line.income_new_is_interfamily = false;
    line.income_new_creditor_id = null;
    line.income_new_creditor_name = '';
    line.income_new_description = '';
    line.income_new_interest_enabled = false;
    line.income_new_interest_rate = 0;
    line.is_repayment_mode = false;
    line.repayment_for_user_id = null;
    line.repayment_links = [];
  } else {
    line.is_repayment_mode = false;
    line.repayment_for_user_id = null;
    line.repayment_links = [];
    line.income_debt_mode = 'none';
    line.income_existing_debt_id = null;
    line.income_new_is_family_debt = false;
    line.income_new_is_interfamily = false;
    line.income_new_creditor_id = null;
    line.income_new_creditor_name = '';
    line.income_new_description = '';
    line.income_new_interest_enabled = false;
    line.income_new_interest_rate = 0;
    line.pay_toward_debt = false;
    line.debt_id = null;
    applySplitLineCategoryDefaults(line);
  }
}

function setSplitLineIncomeDebtMode(line, mode) {
  line.income_debt_mode = mode;
  if (mode === 'existing') {
    line.income_new_is_family_debt = false;
    line.income_new_is_interfamily = false;
    line.income_new_creditor_id = null;
    line.income_new_creditor_name = '';
    line.income_new_description = '';
    line.income_new_interest_enabled = false;
    line.income_new_interest_rate = 0;
    if (incomeAttachableDebts.value.length === 1) {
      line.income_existing_debt_id = incomeAttachableDebts.value[0].id;
    } else {
      line.income_existing_debt_id = null;
    }

    return;
  }
  line.income_existing_debt_id = null;
  if (mode !== 'new') {
    line.income_debt_mode = 'none';
    line.income_new_is_family_debt = false;
    line.income_new_is_interfamily = false;
    line.income_new_creditor_id = null;
    line.income_new_creditor_name = '';
    line.income_new_description = '';
    line.income_new_interest_enabled = false;
    line.income_new_interest_rate = 0;
  }
}

function toggleSplitLinePayTowardDebt(line) {
  line.pay_toward_debt = !line.pay_toward_debt;
  if (line.pay_toward_debt) {
    line.advance_fund_id = null;
    line.is_non_necessity = false;
    const pd = payableDebts.value;
    if (pd.length === 1) {
      line.debt_id = pd[0].id;
    } else if (!pd.some((d) => Number(d.id) === Number(line.debt_id))) {
      line.debt_id = null;
    }
  } else {
    line.debt_id = null;
    applySplitLineCategoryDefaults(line);
  }
}

function toggleSplitLineFamilySplit(line) {
  line.is_split = !line.is_split;
  if (!line.is_split) {
    line.split_data = [];
    line.is_non_necessity = false;

    return;
  }
  if (!familyUsers.value.length || !hasPositiveSplitShares(line.split_data)) {
    line.split_data = equalSplitPayloadForFamilyUsers(familyUsers.value);
  }
}

function toggleSplitLineAdvanceFund(line) {
  if (line.advance_fund_id !== null) {
    line.advance_fund_id = null;
    line.is_non_necessity = false;
  } else {
    line.advance_fund_id = funds.value.length > 0 ? funds.value[0].id : null;
  }
}

function onSplitLineAdvanceFundChange(line) {
  if (line.advance_fund_id === null) {
    line.is_non_necessity = false;
  } else if (!splitLineFundHasNonNecessityRule(line)) {
    line.is_non_necessity = false;
  }
}

function splitLineAmount(line) {
  const parsed = parseFloat(line.amount);

  return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function validateSplitLine(line) {
  if (line.type === 'expense' && line.pay_toward_debt) {
    if (!line.debt_id) {
      return 'Select which debt you are paying toward.';
    }
  }
  if (line.type === 'income') {
    if (line.income_debt_mode === 'existing' && !line.income_existing_debt_id) {
      return 'Select which existing debt this income belongs to.';
    }
    if (line.income_debt_mode === 'new') {
      if (line.income_new_is_interfamily && !line.income_new_creditor_id) {
        return 'Select which family member is the creditor.';
      }
      if (!line.income_new_is_interfamily && !String(line.income_new_creditor_name || '').trim()) {
        return 'Enter the creditor name for the new debt.';
      }
      if (line.income_new_interest_enabled) {
        const interestRate = Number(line.income_new_interest_rate);
        if (!Number.isFinite(interestRate) || interestRate < 0 || interestRate > 100) {
          return 'Interest rate must be between 0 and 100.';
        }
      }
    }
    if (line.is_repayment_mode) {
      if (!line.repayment_for_user_id) {
        return 'Select which family member is repaying you.';
      }
      if (!line.repayment_links?.length) {
        return 'Select at least one expense transaction to link this repayment to.';
      }
      const linksTotal = line.repayment_links.reduce(
        (sum, link) => sum + (parseFloat(link.amount) || 0),
        0,
      );
      const lineAmount = parseFloat(line.amount) || 0;
      if (Math.abs(linksTotal - lineAmount) >= 0.01) {
        return 'The sum of repayment amounts must equal the line amount.';
      }
    }
  }
  if (line.type === 'expense' && line.is_split && !hasPositiveSplitShares(line.split_data)) {
    return 'Add split shares for each family member.';
  }

  return '';
}

function buildSplitLineRepaymentPayload(line) {
  if (line.type !== 'income' || !line.is_repayment_mode) {
    return { is_repayment_mode: false };
  }

  return {
    is_repayment_mode: true,
    repayment_for_user_id: line.repayment_for_user_id,
    repayment_links: (line.repayment_links || []).map((link) => ({
      transaction_id: link.transaction_id,
      amount: parseFloat(link.amount),
    })),
  };
}

function buildSplitLinePayload(line) {
  const payTowardDebt = line.type === 'expense' && line.pay_toward_debt;

  return {
    amount: parseFloat(line.amount),
    type: line.type,
    category_id: Number(line.category_id),
    description: line.description?.trim() || undefined,
    is_split: line.type === 'expense' && line.is_split,
    advance_fund_id: line.type === 'expense' && !payTowardDebt ? line.advance_fund_id || null : null,
    is_non_necessity:
      line.type === 'expense' &&
      !payTowardDebt &&
      !line.is_split &&
      line.advance_fund_id !== null &&
      splitLineFundHasNonNecessityRule(line)
        ? Boolean(line.is_non_necessity)
        : false,
    ...(line.type === 'expense' && line.is_split ? { split_data: line.split_data } : {}),
    ...(line.type === 'expense' && payTowardDebt && line.debt_id ? { debt_id: line.debt_id } : {}),
    ...(line.type === 'income'
      ? {
          income_debt_mode: line.income_debt_mode,
          income_existing_debt_id: line.income_debt_mode === 'existing' ? line.income_existing_debt_id : null,
          income_new_is_family_debt: line.income_debt_mode === 'new' ? Boolean(line.income_new_is_family_debt) : false,
          income_new_is_interfamily: line.income_debt_mode === 'new' ? Boolean(line.income_new_is_interfamily) : false,
          income_new_creditor_id:
            line.income_debt_mode === 'new' && line.income_new_is_interfamily ? line.income_new_creditor_id : null,
          income_new_creditor_name:
            line.income_debt_mode === 'new' && !line.income_new_is_interfamily ? line.income_new_creditor_name : null,
          income_new_description:
            line.income_debt_mode === 'new' && String(line.income_new_description || '').trim()
              ? line.income_new_description
              : null,
          income_new_interest_enabled: line.income_debt_mode === 'new' ? Boolean(line.income_new_interest_enabled) : false,
          income_new_interest_rate:
            line.income_debt_mode === 'new' && line.income_new_interest_enabled ? line.income_new_interest_rate : null,
          ...buildSplitLineRepaymentPayload(line),
        }
      : { is_repayment_mode: false }),
  };
}

function splitAllocated(row) {
  if (!splitModes[row.id]) {
    return 0;
  }

  return splitModes[row.id].lines.reduce((sum, l) => sum + (parseFloat(l.amount) || 0), 0);
}

function splitRemaining(row) {
  return importAbsAmount(row) - splitAllocated(row);
}

function splitLinesValid(row) {
  const sm = splitModes[row.id];
  if (!sm || !sm.lines || sm.lines.length < 2) {
    return false;
  }
  for (const line of sm.lines) {
    if (!line.category_id || !(parseFloat(line.amount) > 0)) {
      return false;
    }
    if (validateSplitLine(line)) {
      return false;
    }
  }

  return Math.abs(splitRemaining(row)) <= 0.01;
}

async function confirmSplitRow(row) {
  rowErrors[row.id] = '';
  const sm = splitModes[row.id];
  if (!sm || !sm.lines) {
    return;
  }
  for (const line of sm.lines) {
    const lineError = validateSplitLine(line);
    if (lineError) {
      rowErrors[row.id] = lineError;

      return;
    }
  }
  if (!splitLinesValid(row)) {
    rowErrors[row.id] = 'Each line needs a category and a positive amount. All line amounts must sum to the total.';

    return;
  }
  actionId.value = row.id;
  try {
    await post(`/plaid/pending-imports/${row.id}/confirm-split`, {
      lines: sm.lines.map((l) => buildSplitLinePayload(l)),
    });
    delete splitModes[row.id];
    removePendingRow(row.id);
    showToast(`Split into ${sm.lines.length} transactions.`, 'success');
  } catch (err) {
    console.error(err);
    rowErrors[row.id] = err.response?.data?.message || err.response?.data?.errors?.lines?.[0] || 'Could not confirm split.';
  } finally {
    actionId.value = null;
  }
}

function removeTransferRow(id) {
  const i = transferImports.value.findIndex((r) => r.id === id);
  if (i !== -1) {
    transferImports.value.splice(i, 1);
  }
}

function ensureAutoCreatedForm(row) {
  if (autoCreatedForms[row.id]) {
    return;
  }
  const tx = row.transaction;
  autoCreatedForms[row.id] = {
    type: tx?.type ?? 'expense',
    category_id: tx?.category_id ? String(tx.category_id) : '',
    fund_id: tx?.fund_id ? String(tx.fund_id) : '',
    advance_fund_id: tx?.advance_fund_id ?? null,
    is_non_necessity: Boolean(tx?.is_non_necessity),
    correcting: false,
  };
}

function autoCreatedFormFor(row) {
  ensureAutoCreatedForm(row);
  return autoCreatedForms[row.id];
}

function removeAutoCreatedRow(id) {
  const i = autoCreatedImports.value.findIndex((r) => r.id === id);
  if (i !== -1) {
    autoCreatedImports.value.splice(i, 1);
  }
  delete autoCreatedForms[id];
  delete rowErrors[id];
  if (expandedAutoCreatedId.value === id) {
    expandedAutoCreatedId.value = null;
  }
}

function ensureDismissedForm(row) {
  if (dismissedForms[row.id]) {
    return;
  }
  dismissedForms[row.id] = {
    type: row.suggested_type === 'income' ? 'income' : 'expense',
    category_id: '',
    fund_id: '',
    advance_fund_id: null,
    is_non_necessity: false,
    description: '',
    restoring: false,
  };
}

function dismissedFormFor(row) {
  ensureDismissedForm(row);
  return dismissedForms[row.id];
}

function removeDismissedRow(id) {
  const i = dismissedImports.value.findIndex((r) => r.id === id);
  if (i !== -1) {
    dismissedImports.value.splice(i, 1);
  }
  delete dismissedForms[id];
  delete rowErrors[id];
}

async function approveAutoCreated(row) {
  rowErrors[row.id] = '';
  actionId.value = row.id;
  try {
    await post(`/plaid/pending-imports/${row.id}/approve-auto-created`, {});
    removeAutoCreatedRow(row.id);
    showToast('Marked as correct. Confidence updated.', 'success');
  } catch (err) {
    rowErrors[row.id] = err.response?.data?.message || 'Could not approve.';
  } finally {
    actionId.value = null;
  }
}

async function submitAutoCreatedCorrection(row) {
  rowErrors[row.id] = '';
  const f = autoCreatedFormFor(row);
  if (!f.category_id) {
    rowErrors[row.id] = 'Choose a category.';
    return;
  }
  actionId.value = row.id;
  try {
    await post(`/plaid/pending-imports/${row.id}/correct-auto-created`, {
      category_id: Number(f.category_id),
      type: f.type,
      fund_id: f.fund_id ? Number(f.fund_id) : null,
      advance_fund_id: f.advance_fund_id ?? null,
      is_non_necessity: Boolean(f.is_non_necessity),
    });
    removeAutoCreatedRow(row.id);
    showToast('Transaction corrected. Rule updated.', 'success');
  } catch (err) {
    rowErrors[row.id] = err.response?.data?.message || 'Could not correct.';
  } finally {
    actionId.value = null;
  }
}

async function acknowledgeAutoDismiss(row) {
  rowErrors[row.id] = '';
  actionId.value = row.id;
  try {
    await post(`/plaid/pending-imports/${row.id}/acknowledge-auto-dismiss`, {});
    removeDismissedRow(row.id);
    showToast('Confirmed — this merchant will continue to be auto-ignored.', 'success');
  } catch (err) {
    rowErrors[row.id] = err.response?.data?.message || 'Could not acknowledge.';
  } finally {
    actionId.value = null;
  }
}

async function restoreFromDismiss(row) {
  rowErrors[row.id] = '';
  const f = dismissedFormFor(row);
  if (!f.category_id) {
    rowErrors[row.id] = 'Choose a category first.';
    return;
  }
  actionId.value = row.id;
  try {
    await post(`/plaid/pending-imports/${row.id}/restore-from-dismiss`, {
      category_id: Number(f.category_id),
      type: f.type,
      fund_id: f.fund_id ? Number(f.fund_id) : null,
      advance_fund_id: f.advance_fund_id ?? null,
      is_non_necessity: Boolean(f.is_non_necessity),
      description: f.description?.trim() || undefined,
    });
    removeDismissedRow(row.id);
    showToast('Transaction created. Merchant rule updated — future imports will appear for review.', 'success');
  } catch (err) {
    rowErrors[row.id] = err.response?.data?.message || 'Could not restore.';
  } finally {
    actionId.value = null;
  }
}

function applyDefaultTab() {
  if (transferImports.value.length > 0 && pendingImports.value.length === 0) {
    activeTab.value = 'transfers';
  } else {
    activeTab.value = 'review';
  }
}

function validateConfirmForm(row, f) {
  if (f.type === 'expense' && f.pay_toward_debt) {
    if (!f.debt_id) {
      return 'Select which debt you are paying toward.';
    }
  }
  if (f.type === 'income') {
    if (f.income_debt_mode === 'existing' && !f.income_existing_debt_id) {
      return 'Select which existing debt this income belongs to.';
    }
    if (f.income_debt_mode === 'new') {
      if (f.income_new_is_interfamily && !f.income_new_creditor_id) {
        return 'Select which family member is the creditor.';
      }
      if (!f.income_new_is_interfamily && !String(f.income_new_creditor_name || '').trim()) {
        return 'Enter the creditor name for the new debt.';
      }
      if (f.income_new_interest_enabled) {
        const interestRate = Number(f.income_new_interest_rate);
        if (!Number.isFinite(interestRate) || interestRate < 0 || interestRate > 100) {
          return 'Interest rate must be between 0 and 100.';
        }
      }
    }
  }
  if (f.type === 'expense' && f.is_split && !hasPositiveSplitShares(f.split_data)) {
    return 'Add split shares for each family member.';
  }

  return '';
}

function buildConfirmPayload(row, f) {
  const payTowardDebt = f.type === 'expense' && f.pay_toward_debt;
  const payload = {
    category_id: Number(f.category_id),
    type: f.type,
    description: f.description?.trim() || undefined,
    is_split: f.type === 'expense' && f.is_split,
    advance_fund_id: f.type === 'expense' && !payTowardDebt ? f.advance_fund_id || null : null,
    is_non_necessity:
      f.type === 'expense' &&
      !payTowardDebt &&
      !f.is_split &&
      f.advance_fund_id !== null &&
      selectedFundHasNonNecessityRule(f)
        ? Boolean(f.is_non_necessity)
        : false,
    ...(f.type === 'expense' && f.is_split ? { split_data: f.split_data } : {}),
    ...(f.type === 'expense' && payTowardDebt && f.debt_id ? { debt_id: f.debt_id } : {}),
    ...(f.type === 'income'
      ? {
          income_debt_mode: f.income_debt_mode,
          income_existing_debt_id: f.income_debt_mode === 'existing' ? f.income_existing_debt_id : null,
          income_new_is_family_debt: f.income_debt_mode === 'new' ? Boolean(f.income_new_is_family_debt) : false,
          income_new_is_interfamily: f.income_debt_mode === 'new' ? Boolean(f.income_new_is_interfamily) : false,
          income_new_creditor_id:
            f.income_debt_mode === 'new' && f.income_new_is_interfamily ? f.income_new_creditor_id : null,
          income_new_creditor_name:
            f.income_debt_mode === 'new' && !f.income_new_is_interfamily ? f.income_new_creditor_name : null,
          income_new_description:
            f.income_debt_mode === 'new' && String(f.income_new_description || '').trim()
              ? f.income_new_description
              : null,
          income_new_interest_enabled: f.income_debt_mode === 'new' ? Boolean(f.income_new_interest_enabled) : false,
          income_new_interest_rate:
            f.income_debt_mode === 'new' && f.income_new_interest_enabled ? f.income_new_interest_rate : null,
        }
      : {}),
  };
  return payload;
}

async function loadAll() {
  loading.value = true;
  pageError.value = '';
  try {
    const [pendingRes, catRes, fundRes, debtsRes, usersRes] = await Promise.all([
      window.axios.get('/plaid/pending-imports'),
      window.axios.get('/categories'),
      window.axios.get('/funds'),
      window.axios.get('/debts'),
      window.axios.get('/family/users'),
    ]);
    pendingImports.value = Array.isArray(pendingRes.data?.pending) ? pendingRes.data.pending : [];
    transferImports.value = Array.isArray(pendingRes.data?.transfers) ? pendingRes.data.transfers : [];
    autoCreatedImports.value = Array.isArray(pendingRes.data?.auto_created) ? pendingRes.data.auto_created : [];
    dismissedImports.value = Array.isArray(pendingRes.data?.dismissed) ? pendingRes.data.dismissed : [];
    categories.value = Array.isArray(catRes.data) ? catRes.data : [];
    funds.value = Array.isArray(fundRes.data) ? fundRes.data : [];
    const db = debtsRes.data;
    debtsPayload.value =
      db && typeof db === 'object'
        ? {
            owed: Array.isArray(db.owed) ? db.owed : [],
            owing: Array.isArray(db.owing) ? db.owing : [],
            family_debts: Array.isArray(db.family_debts) ? db.family_debts : [],
          }
        : { owed: [], owing: [], family_debts: [] };
    familyUsers.value = Array.isArray(usersRes.data) ? usersRes.data : [];
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
  const validationMessage = validateConfirmForm(row, f);
  if (validationMessage) {
    rowErrors[row.id] = validationMessage;

    return;
  }
  actionId.value = row.id;
  try {
    const payload = buildConfirmPayload(row, f);
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
