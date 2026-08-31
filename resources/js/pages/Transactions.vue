<template>
  <div class="pb-32">
    <!-- Header -->
    <div class="sticky top-0 pt-safe bg-gray-900 border-b border-gray-800 px-4 py-3 z-10 flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-white">Transactions</h1>
        <p class="text-gray-400 text-sm mt-1">
          {{ isFamilyExpenseView ? 'Showing all family expenses' : 'Manage your spending and income' }}
        </p>
      </div>
      <div
        v-if="showCloseOutHeaderButton"
        class="shrink-0 pt-0.5"
      >
        <button
          type="button"
          @click="isUserSoftClosed ? handleUndoSoftClose() : handleSoftClose()"
          :class="[
            'max-w-[11rem] sm:max-w-none text-right sm:text-left rounded-lg text-xs sm:text-sm font-medium transition-colors px-3 py-2 leading-tight',
            isUserSoftClosed
              ? 'bg-gray-700 hover:bg-gray-600 text-gray-300'
              : 'bg-blue-600 hover:bg-blue-700 text-white'
          ]"
        >
          {{ isUserSoftClosed ? 'Undo' : 'Close Out' }}
        </button>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-gray-900 border-b border-gray-800 px-4 py-3 space-y-3 min-w-0 max-w-full overflow-x-hidden">
      <div class="flex min-w-0 items-stretch gap-2">
        <div
          v-if="currentMonthYear && selectedMonthFilter !== 'custom'"
          class="shrink-0 flex w-11 items-center justify-center rounded-lg border bg-gray-800"
          :class="monthLockUi.borderClass"
          :title="monthLockUi.title"
        >
          <!-- Hard-closed: locked -->
          <svg
            v-if="isCurrentMonthHardClosed"
            class="h-5 w-5 text-amber-400"
            fill="currentColor"
            viewBox="0 0 20 20"
            aria-hidden="true"
          >
            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
          </svg>
          <!-- You've marked your month; not hard-closed yet -->
          <svg
            v-else-if="isUserSoftClosed"
            class="h-5 w-5 text-blue-400"
            fill="currentColor"
            viewBox="0 0 20 20"
            aria-hidden="true"
          >
            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
          </svg>
          <!-- Open for you -->
          <svg
            v-else
            class="h-5 w-5 text-gray-400"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
          </svg>
        </div>
        <select
          v-model="selectedMonthFilter"
          @change="handleMonthFilterChange"
          class="min-w-0 grow bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:outline-none focus:border-blue-500"
        >
          <optgroup label="Quick Select">
            <option
              v-for="month in quickSelectMonths"
              :key="month.value"
              :value="month.value"
              :class="{ 'text-gray-500': isMonthClosed(month.value.split('-')[0], month.value.split('-')[1]) }"
            >
              {{ month.label }}
            </option>
          </optgroup>
          <optgroup label="Custom">
            <option value="custom">Custom Range</option>
          </optgroup>
        </select>
        <button
          v-if="selectedMonthFilter && selectedMonthFilter !== 'custom'"
          type="button"
          @click="navigateToMonthSummary"
          class="shrink-0 px-3 py-2 bg-gray-700 hover:bg-gray-600 text-gray-200 text-sm rounded-lg font-medium transition-colors"
        >
          View
        </button>
      </div>

      <!-- Custom Date Range Inputs (stack on narrow screens so native date inputs do not overflow) -->
      <div
        v-if="selectedMonthFilter === 'custom'"
        class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-end sm:gap-2"
      >
        <div class="min-w-0 w-full flex-1 sm:min-w-0">
          <label class="block text-xs text-gray-400 mb-1">From</label>
          <input
            v-model="customStartDate"
            type="date"
            class="w-full min-w-0 max-w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:outline-none focus:border-blue-500"
          />
        </div>
        <div class="min-w-0 w-full flex-1 sm:min-w-0">
          <label class="block text-xs text-gray-400 mb-1">To</label>
          <input
            v-model="customEndDate"
            type="date"
            class="w-full min-w-0 max-w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:outline-none focus:border-blue-500"
          />
        </div>
        <button
          type="button"
          @click="applyCustomRange"
          class="w-full shrink-0 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg font-medium transition-colors sm:w-auto"
        >
          Apply
        </button>
      </div>
    </div>

    <!-- Period totals (current filter range; shown even when there are no rows) -->
    <div
      v-if="!loading && !error"
      class="mx-4 mt-3 rounded-xl border border-gray-700 bg-gray-800 p-3"
    >
      <div class="flex justify-between gap-4">
        <div>
          <p class="text-xs font-medium uppercase text-gray-400">Income</p>
          <template v-if="isFamilyExpenseView">
            <p class="mt-0.5 text-[11px] text-gray-500">
              You <span class="font-semibold text-green-400 tabular-nums">+{{ formatCurrency(totalIncome) }}</span>
            </p>
            <p class="text-[11px] text-gray-500">
              Family <span class="font-semibold text-green-400 tabular-nums">+{{ formatCurrency(familyTotalIncome) }}</span>
            </p>
          </template>
          <p v-else class="font-semibold text-green-400">+{{ formatCurrency(totalIncome) }}</p>
        </div>
        <div class="text-right">
          <p class="text-xs font-medium uppercase text-gray-400">Expenses</p>
          <template v-if="isFamilyExpenseView">
            <p class="mt-0.5 text-[11px] text-gray-500">
              You <span class="font-semibold text-red-400 tabular-nums">−{{ formatCurrency(totalExpenses) }}</span>
            </p>
            <p class="text-[11px] text-gray-500">
              Family <span class="font-semibold text-red-400 tabular-nums">−{{ formatCurrency(familyTotalExpenses) }}</span>
            </p>
          </template>
          <p v-else class="font-semibold text-red-400">−{{ formatCurrency(totalExpenses) }}</p>
        </div>
      </div>
      <template v-if="hasNonNecessityExpenses">
        <div class="mt-2 border-t border-gray-700/60 pt-2 space-y-1">
          <div class="flex justify-between text-xs">
            <span class="text-gray-400">Total Necessities</span>
            <span class="text-red-400 tabular-nums">−{{ formatCurrency(totalNecessityExpenses) }}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-gray-400">Total Non-Necessities</span>
            <span class="text-violet-400 tabular-nums">−{{ formatCurrency(totalNonNecessityExpenses) }}</span>
          </div>
        </div>
      </template>
      <p
        class="text-center text-[10px] text-gray-500 leading-snug"
        :class="hasNonNecessityExpenses ? 'mt-2' : 'mt-2 border-t border-gray-700/60 pt-2'"
      >
        Split <span class="text-gray-400">expenses</span> use <span class="text-gray-400">your share</span> in the expense total and in each day’s expense sum.
        <span class="block mt-1 text-gray-500">Income totals exclude <span class="text-sky-300/90">debt repayments</span> received (they do not count as earned income for closeout).</span>
        <span v-if="isFamilyExpenseView" class="block mt-1 text-gray-500">Family totals use the full amount of each household transaction (splits counted once). Payments from one family member to another are not counted as family expenses.</span>
      </p>
    </div>

    <div
      v-if="
        !loading &&
        !error &&
        currentUser?.family_id &&
        selectedMonthFilter !== 'custom' &&
        monthSplitBalances.length > 0
      "
      class="mx-4 mt-3 rounded-xl border border-gray-700 bg-gray-800 p-3 min-w-0 max-w-full"
    >
      <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Split balances (this month)</h2>
      <p class="mt-1 text-[10px] text-gray-500 leading-snug">
        From shared expenses in this calendar month only. Split debt repayments are included; closeout-generated ledger lines are excluded.
      </p>
      <div class="mt-3 space-y-2">
        <div
          v-for="balance in monthSplitBalances"
          :key="'split-bal-' + balance.user_id"
          class="flex items-center justify-between gap-2 rounded-lg border border-gray-700 bg-gray-900/40 px-3 py-2"
        >
          <span class="text-sm text-gray-300 min-w-0 pr-2">
            <template v-if="balance.direction === 'they_owe_you'">
              {{ balance.user_name }} owes you
            </template>
            <template v-else>
              You owe {{ balance.user_name }}
            </template>
          </span>
          <span
            :class="balance.direction === 'they_owe_you' ? 'text-green-400' : 'text-red-400'"
            class="text-sm font-medium shrink-0 tabular-nums"
          >
            {{ formatCurrency(balance.net_amount) }}
          </span>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="text-center">
        <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        <p class="text-gray-400">Loading transactions...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="m-4 p-4 bg-red-900/20 border border-red-700/50 rounded-lg">
      <p class="text-red-400 text-sm">{{ error }}</p>
      <button @click="fetchData" class="mt-2 text-xs text-red-400 hover:text-red-300 underline">
        Try again
      </button>
    </div>

    <!-- Empty State -->
    <div v-else-if="transactions.length === 0" class="flex items-center justify-center py-8">
      <div class="text-center">
        <svg class="w-12 h-12 text-gray-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m0 0h6m0 0V6m0 0H6m0 0V3" />
        </svg>
        <p class="text-gray-400 font-medium">No transactions for this period</p>
        <p class="text-gray-500 text-sm">
          {{ isSelectedMonthLocked ? 'This month is closed, so new transactions are blocked.' : 'Tap the + button to add a transaction' }}
        </p>
      </div>
    </div>

    <!-- Transactions List (grouped by day; closeout fund transfers are a separate group) -->
    <div v-if="!loading && transactions.length > 0" class="space-y-0 px-0 py-4">
      <div v-if="closeoutFundMovementTransactions.length > 0">
        <div class="flex items-center justify-between px-4 py-1.5 mt-2">
          <span class="text-[10px] sm:text-sm font-semibold text-blue-300">
            Closeout fund movements
          </span>
        </div>
        <div class="space-y-2 px-4 py-1.5">
          <div
            v-for="transaction in closeoutFundMovementTransactions"
            :key="'closeout-fund-' + transaction.id"
            class="flex overflow-hidden rounded-lg sm:rounded-xl cursor-default bg-blue-950/25"
          >
            <div
              v-if="isFamilyExpenseView && isTransactionOwnedByOther(transaction)"
              class="w-1 shrink-0 self-stretch bg-yellow-500"
            ></div>
            <div class="min-w-0 flex-1">
            <div class="p-2 sm:p-3">
            <div class="flex min-w-0 flex-row items-start justify-between gap-2 sm:gap-3">
              <div class="min-w-0 flex-1">
                <p class="text-[11px] sm:text-base font-medium truncate leading-tight text-blue-100">
                  {{ closeoutFundName(transaction) }}
                </p>
                <p
                  v-if="isFamilyExpenseView && isTransactionOwnedByOther(transaction)"
                  class="mt-0.5 flex min-w-0 items-center gap-1 text-[11px] text-yellow-400"
                >
                  <svg class="h-3.5 w-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                    <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z" />
                  </svg>
                  <span class="truncate">{{ transactionPayerDisplayLabel(transaction) }}</span>
                </p>
              </div>
              <span class="text-sm sm:text-base font-medium shrink-0 tabular-nums text-blue-300">
                −{{ formatCurrency(transaction.amount) }}
              </span>
            </div>
            </div>
            <div class="h-2 w-full" :style="closeoutFundTypeBarStyle()"></div>
            </div>
          </div>
        </div>
      </div>

      <div v-for="dayGroup in transactionsByDay" :key="dayGroup.date">
        <!-- Day Header -->
        <div class="flex items-center justify-between px-4 py-1.5 mt-2">
          <span class="text-[10px] sm:text-sm font-semibold text-gray-400">
            {{ formatDate(dayGroup.date) }}
          </span>
            <div
              v-if="isFamilyExpenseView"
              class="grid grid-cols-[max-content_max-content] gap-x-3 gap-y-0.5 justify-items-start text-[10px] sm:text-xs font-medium leading-tight tabular-nums"
            >
              <template v-if="dayGroup.youTotalIncome > 0.005 || dayGroup.familyTotalIncome > 0.005">
                <span class="whitespace-nowrap text-green-400/80">Family +{{ formatCurrency(dayGroup.familyTotalIncome) }}</span>
                <span class="whitespace-nowrap text-green-400">You +{{ formatCurrency(dayGroup.youTotalIncome) }}</span>
              </template>
              <template v-if="dayGroup.youTotalExpenses > 0.005 || dayGroup.familyTotalExpenses > 0.005">
                <span class="whitespace-nowrap text-red-400/80">Family −{{ formatCurrency(dayGroup.familyTotalExpenses) }}</span>
                <span class="whitespace-nowrap text-red-400">You −{{ formatCurrency(dayGroup.youTotalExpenses) }}</span>
              </template>
            </div>
            <div v-else class="flex flex-col items-end gap-0.5 text-right leading-tight">
              <span v-if="dayGroup.youTotalIncome > 0.005" class="text-[10px] sm:text-xs font-medium text-green-400">
                +{{ formatCurrency(dayGroup.youTotalIncome) }}
              </span>
              <span v-if="dayGroup.youTotalExpenses > 0.005" class="text-[10px] sm:text-xs font-medium text-red-400">
                −{{ formatCurrency(dayGroup.youTotalExpenses) }}
              </span>
            </div>
        </div>

        <!-- Day's Transactions -->
        <div class="flex flex-col gap-2 px-4 py-1.5">
          <template v-for="transaction in dayGroup.transactions" :key="transaction.id">
          <div
            v-if="isFirstUnassociatedFamilySoloOfDay(dayGroup, transaction)"
            class="flex items-center justify-center py-0.5"
            aria-hidden="true"
          >
            <span class="block h-1.5 w-1.5 rounded-full bg-gray-500"></span>
          </div>
          <div
            :class="[
              'flex overflow-hidden rounded-lg sm:rounded-xl transition-colors',
              confirmDelete[transaction.id]
                ? 'bg-red-900/20'
                : transactionRowBackgroundClass(transaction),
              transaction.is_repaid || isExternalReimbursementIncome(transaction) ? 'opacity-40' : '',
              isSelectedMonthLocked && !usesReimbursementCardStyle(transaction) ? 'opacity-75' : '',
              !confirmDelete[transaction.id] && !isSelectedMonthLocked && isTransactionEditLocked(transaction) ? 'cursor-default' : '',
              !confirmDelete[transaction.id] && !isSelectedMonthLocked && !isTransactionEditLocked(transaction) ? 'cursor-pointer' : '',
            ]"
            @click="!confirmDelete[transaction.id] && !isSelectedMonthLocked && !isTransactionEditLocked(transaction) && openEditForm(transaction.id)"
          >
            <div
              v-if="isTransactionOwnedByOther(transaction)"
              class="w-1 shrink-0 self-stretch bg-yellow-500"
            ></div>
            <div class="min-w-0 flex-1">
            <div class="p-2 sm:p-3">
            <!-- Main transaction row: one horizontal row on all breakpoints so amount + split stay beside title on mobile -->
            <div class="flex min-w-0 flex-row items-start justify-between gap-2 sm:gap-3">
              <div
                class="min-w-0 flex-1"
                :class="!confirmDelete[transaction.id] && !isSelectedMonthLocked && !isTransactionEditLocked(transaction) && 'cursor-pointer'"
              >
                <div class="flex items-center gap-1.5 sm:gap-2 mb-0.5 sm:mb-1 min-w-0 flex-wrap">
                  <span
                    v-if="transaction.category?.icon"
                    class="text-sm sm:text-base shrink-0"
                  >
                    {{ transaction.category.icon }}
                  </span>
                  <span class="text-[11px] sm:text-base text-gray-300 font-medium truncate min-w-0 flex-1 leading-tight">
                    {{ getTransactionCategoryLabel(transaction) }}
                  </span>
                  <component
                    :is="pill.onClick ? 'button' : 'span'"
                    v-for="pill in transactionKindPills(transaction)"
                    :key="pill.key"
                    :type="pill.onClick ? 'button' : undefined"
                    :title="pill.title || undefined"
                    class="inline-flex shrink-0 items-center rounded-md px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide"
                    :class="pill.classes"
                    @click.stop="pill.onClick && openPillModal('debt', transaction)"
                  >
                    {{ pill.label }}
                  </component>
                  <button
                    v-if="transaction.is_repaid && transaction.repaid_by_link?.is_external_repayment"
                    type="button"
                    class="inline-flex shrink-0 items-center rounded-md border border-cyan-700/30 bg-cyan-900/50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-cyan-300 cursor-pointer hover:bg-cyan-800/50"
                    @click.stop="openPillModal('reimbursed-externally', transaction)"
                  >
                    Reimbursed externally
                  </button>
                  <button
                    v-else-if="transaction.is_repaid && !transaction.repaid_by_link?.is_external_repayment"
                    type="button"
                    class="inline-flex shrink-0 items-center rounded-md border border-cyan-700/30 bg-cyan-900/50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-cyan-300 cursor-pointer hover:bg-cyan-800/50"
                    @click.stop="openPillModal('repaid-by', transaction)"
                  >
                    Repaid by {{ transaction.repaid_by_link?.repaid_user?.name ?? 'family member' }}
                  </button>
                  <button
                    v-if="transaction.is_repayment && transaction.repayment_links?.[0]?.is_external_repayment"
                    type="button"
                    class="inline-flex shrink-0 items-center rounded-md border border-cyan-700/30 bg-cyan-900/50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-cyan-300 cursor-pointer hover:bg-cyan-800/50"
                    @click.stop="openPillModal('external-reimbursement', transaction)"
                  >
                    External reimbursement
                  </button>
                  <button
                    v-else-if="transaction.is_repayment"
                    type="button"
                    class="inline-flex shrink-0 items-center rounded-md border border-cyan-700/30 bg-cyan-900/50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-cyan-300 cursor-pointer hover:bg-cyan-800/50"
                    @click.stop="openPillModal('repayment-covered', transaction)"
                  >
                    Repayment received
                  </button>
                  <span
                    v-if="transaction.is_repayment_mirror"
                    class="inline-flex shrink-0 items-center rounded-md border border-amber-700/30 bg-amber-900/50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-amber-300"
                  >
                    Needs Review — repaid{{ transaction.mirror_repayment_link?.repayment_transaction?.user?.name ? ' on behalf of ' + transaction.mirror_repayment_link.repayment_transaction.user.name : '' }}
                  </span>
                  <button
                    v-if="transaction.is_debt_payment_benefit && !isTransactionOwnedByOther(transaction)"
                    type="button"
                    class="inline-flex shrink-0 items-center rounded-md border border-emerald-700/30 bg-emerald-900/50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-emerald-300 cursor-pointer hover:bg-emerald-800/50"
                    @click.stop="openBenefitFormFromBenefit(transaction)"
                  >
                    From debt repayment
                  </button>
                  <span
                    v-else-if="transaction.is_debt_payment_benefit"
                    class="inline-flex shrink-0 items-center rounded-md border border-emerald-700/30 bg-emerald-900/50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-emerald-300"
                  >
                    From debt repayment
                  </span>
                  <button
                    v-else-if="transaction.is_debt_payment && transaction.type === 'income' && transaction.debt_payment_benefit_expense && !isTransactionOwnedByOther(transaction)"
                    type="button"
                    class="inline-flex shrink-0 items-center rounded-md border border-emerald-700/30 bg-emerald-900/50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-emerald-300 cursor-pointer hover:bg-emerald-800/50"
                    @click.stop="openBenefitForm(transaction)"
                  >
                    Also recorded as expense
                  </button>
                  <button
                    v-else-if="canRecordDebtPaymentBenefit(transaction)"
                    type="button"
                    class="inline-flex shrink-0 items-center rounded-md border border-emerald-700/40 bg-emerald-950/40 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-emerald-200 cursor-pointer hover:bg-emerald-900/50"
                    @click.stop="openBenefitForm(transaction)"
                  >
                    Record as expense
                  </button>
                </div>
                <p v-if="transaction.description" class="hidden sm:block text-gray-400 text-xs truncate">
                  {{ transaction.description }}
                </p>
                <div v-if="transaction.import_source === 'plaid' && transaction.plaid_pending_import?.plaid_item?.institution_name" class="mt-1 flex flex-wrap items-center gap-1.5">
                  <button
                    type="button"
                    class="inline-flex items-center rounded-full border border-blue-800/40 bg-blue-950/30 px-2 py-0.5 text-xs text-blue-300 hover:bg-blue-900/50 hover:border-blue-700/60 transition-colors cursor-pointer"
                    @click.stop="openBankPillModal(transaction)"
                  >
                    🏦 {{ transaction.plaid_pending_import.plaid_item.institution_name }}
                  </button>
                </div>
                <div
                  v-if="transaction.user?.name"
                  class="mt-1.5 flex min-w-0 items-center gap-1 text-xs"
                  :class="[
                    isTransactionOwnedByOther(transaction) ? 'text-yellow-400' : 'text-gray-500',
                    !isFamilyExpenseView && !isTransactionOwnedByOther(transaction) ? 'hidden sm:block' : '',
                  ]"
                >
                  <svg
                    v-if="isTransactionOwnedByOther(transaction)"
                    class="h-3.5 w-3.5 shrink-0"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                    aria-hidden="true"
                  >
                    <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z" />
                  </svg>
                  <span class="truncate">{{ transactionPayerDisplayLabel(transaction) }}</span>
                </div>
              </div>

              <div class="flex shrink-0 items-start gap-1.5 sm:gap-2">
                <div
                  class="flex min-w-0 max-w-[12.5rem] flex-col items-end gap-1 text-right leading-tight sm:max-w-[22rem]"
                >
                  <button
                    v-if="isSplitListRow(transaction)"
                    type="button"
                    class="grid w-max max-w-full grid-cols-[minmax(0,max-content)_max-content] items-baseline gap-x-2 gap-y-0.5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-500/60"
                    title="View how this payment was split"
                    @click.stop="openSplitDetailModal(transaction)"
                  >
                    <template
                      v-for="split in splitsSortedForModal(transaction)"
                      :key="split.id ?? `split-${split.user_id}`"
                    >
                      <span class="min-w-0 truncate text-[9px] sm:text-xs font-medium text-gray-400">
                        {{ splitParticipantLabel(split) }}:
                      </span>
                      <span
                        class="text-right text-[11px] sm:text-base font-bold tabular-nums"
                        :class="transactionAmountColorClass(transaction)"
                      >
                        {{ transaction.type === 'income' ? '+' : '-' }}{{ formatCurrency(Number(split.amount) || 0) }}
                      </span>
                    </template>
                    <span class="min-w-0 truncate text-[9px] sm:text-xs font-semibold text-purple-400">Total:</span>
                    <span class="text-right text-[9px] sm:text-xs font-semibold tabular-nums text-purple-400">
                      {{ formatCurrency(Number(transaction.amount) || 0) }}
                    </span>
                  </button>
                  <span
                    v-else
                    class="text-[11px] sm:text-base font-bold tabular-nums"
                    :class="transactionAmountColorClass(transaction)"
                  >
                    {{ transaction.type === 'income' ? '+' : '-' }}{{ formatCurrency(Number(transaction.amount) || 0) }}
                  </span>
                </div>

                <div class="flex shrink-0 flex-col items-end gap-1 pt-0.5 sm:flex-row sm:items-start sm:gap-1 sm:pt-0">
                  <!-- Lock Icon (if month is closed) -->
                  <svg
                    v-if="isSelectedMonthLocked"
                    class="h-4 w-4 shrink-0"
                    :class="isCurrentMonthHardClosed ? 'text-amber-400' : 'text-blue-400'"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                    :title="isCurrentMonthHardClosed ? 'Month is hard-closed' : 'You have soft-closed this month'"
                  >
                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                  </svg>

                  <!-- Action Buttons -->
                  <div v-if="!isFamilyViewOtherMemberRow(transaction) && !confirmDelete[transaction.id]" class="flex gap-1">
                    <button
                      @click.stop="confirmDelete[transaction.id] = true"
                      :disabled="isSelectedMonthLocked || transaction.is_closeout_initiated || transaction.is_debt_payment_benefit"
                      :class="['p-1 sm:p-2 rounded-md sm:rounded-lg transition-colors', (isSelectedMonthLocked || transaction.is_closeout_initiated || transaction.is_debt_payment_benefit) ? 'text-gray-500 cursor-not-allowed' : 'text-gray-400 hover:text-red-400 hover:bg-gray-700']"
                      :title="deleteButtonTitle(transaction)"
                    >
                      <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>

                  <!-- Delete Confirmation -->
                  <div v-else-if="!isFamilyViewOtherMemberRow(transaction)" class="flex gap-1">
                    <button
                      @click.stop="handleDeleteConfirm(transaction.id)"
                      class="px-2 py-0.5 text-[10px] sm:text-xs bg-red-600 hover:bg-red-700 text-white rounded transition-colors"
                    >
                      Yes
                    </button>
                    <button
                      @click.stop="confirmDelete[transaction.id] = false"
                      class="px-2 py-0.5 text-[10px] sm:text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 rounded transition-colors"
                    >
                      No
                    </button>
                  </div>
                </div>
              </div>
            </div>
            </div>
            <div
              class="h-2 w-full"
              :style="transactionTypeBarStyle(transaction, { confirmDelete: Boolean(confirmDelete[transaction.id]) })"
            ></div>
            </div>
          </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Edit Transaction Modal (on this page only) -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full"
      enter-to-class="translate-y-0"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0"
      leave-to-class="translate-y-full"
    >
      <div v-if="showForm" class="fixed inset-0 z-50">
        <!-- Backdrop -->
        <div
          class="absolute inset-0 bg-black/50"
          @click="showForm = false"
        />
        <!-- Modal -->
        <div class="absolute bottom-0 left-0 right-0 w-full max-w-full min-w-0 bg-gray-900 rounded-t-2xl max-h-[90vh] overflow-y-auto overflow-x-hidden">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex min-w-0 items-center justify-between">
            <h2 class="text-xl font-bold text-white">{{ editingTransactionId ? 'Edit Transaction' : 'New Transaction' }}</h2>
            <button
              @click="handleFormClose"
              class="text-gray-400 hover:text-white"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="p-4 min-w-0 max-w-full">
            <TransactionForm
              v-if="showForm"
              :categories="categories"
              :family-users="familyUsers"
              :funds="funds"
              :debts-payload="debtsPayload"
              :transaction="editingTransactionId ? getTransactionById(editingTransactionId) : null"
              @created="handleTransactionCreated"
              @updated="handleTransactionUpdated"
              @close="handleFormClose"
            />
          </div>
        </div>
      </div>
    </Transition>

    <Teleport to="body">
      <div
        v-if="splitDetailModalTransaction"
        class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center p-0 sm:p-4"
      >
        <div
          class="absolute inset-0 bg-black/60"
          @click="closeSplitDetailModal"
        />
        <div
          class="relative flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-t-2xl border border-gray-700 bg-gray-900 shadow-xl sm:rounded-2xl"
          role="dialog"
          aria-modal="true"
          aria-labelledby="split-detail-title"
        >
          <div class="flex shrink-0 items-center justify-between border-b border-gray-800 px-4 py-3">
            <h2 id="split-detail-title" class="text-lg font-semibold text-white">
              Split breakdown
            </h2>
            <button
              type="button"
              class="rounded p-1 text-gray-400 hover:bg-gray-800 hover:text-white"
              @click="closeSplitDetailModal"
            >
              <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="min-h-0 space-y-2 overflow-y-auto p-4">
            <p
              v-if="splitDetailModalTransaction.is_debt_payment || splitDetailModalTransaction.category?.name || splitDetailModalTransaction.description"
              class="text-sm text-gray-400"
            >
              <span v-if="splitDetailModalTransaction.is_debt_payment || splitDetailModalTransaction.category?.name">{{ getTransactionCategoryLabel(splitDetailModalTransaction) }}</span>
              <span
                v-if="splitDetailModalTransaction.description && !splitDetailModalTransaction.is_debt_payment"
              > · {{ splitDetailModalTransaction.description }}</span>
            </p>
            <div
              v-for="split in splitsSortedForModal(splitDetailModalTransaction)"
              :key="split.id"
              class="flex items-center justify-between gap-3 rounded-lg border border-gray-700 bg-gray-800/80 px-3 py-2.5"
            >
              <div class="min-w-0">
                <span class="font-medium text-gray-200">{{ split.user?.name || 'Member' }}</span>
                <span
                  v-if="isSplitRowForCurrentUser(split)"
                  class="ml-1.5 text-xs font-medium text-purple-400"
                >
                  (You)
                </span>
              </div>
              <div class="shrink-0 text-right tabular-nums">
                <p class="font-semibold text-white">
                  {{ formatCurrency(Number(split.amount) || 0) }}
                </p>
                <p class="text-xs text-gray-400">
                  {{ formatSplitSharePercent(split.share_percentage) }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="pillModal"
        class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center p-0 sm:p-4"
      >
        <div class="absolute inset-0 bg-black/60" @click="closePillModal" />
        <div
          class="relative flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-t-2xl border border-gray-700 bg-gray-900 shadow-xl sm:rounded-2xl"
          role="dialog"
          aria-modal="true"
        >
          <div class="flex shrink-0 items-center justify-between border-b border-gray-800 px-4 py-3">
            <h2 class="text-lg font-semibold text-white">
              <template v-if="pillModal.type === 'debt'">Debt Details</template>
              <template v-else-if="pillModal.type === 'repayment-covered'">Expenses Covered</template>
              <template v-else-if="pillModal.type === 'repaid-by'">Repayment Details</template>
              <template v-else-if="pillModal.type === 'reimbursed-externally'">External Reimbursement</template>
              <template v-else-if="pillModal.type === 'external-reimbursement'">Expenses Reimbursed</template>
            </h2>
            <button type="button" class="rounded p-1 text-gray-400 hover:bg-gray-800 hover:text-white" @click="closePillModal">
              <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="min-h-0 overflow-y-auto p-4 space-y-3">
            <template v-if="pillModal.type === 'debt' && pillModal.transaction.debt">
              <div class="rounded-lg border border-gray-700 bg-gray-800/80 p-3 space-y-2">
                <div v-if="pillModal.transaction.debt.description" class="text-sm text-white font-medium">
                  {{ pillModal.transaction.debt.description }}
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Owed to</span>
                  <span class="text-gray-200 font-medium">
                    {{ pillModal.transaction.debt.creditor?.name
                      || pillModal.transaction.debt.creditor_name
                      || pillModal.transaction.debt.fund?.name
                      || '—' }}
                  </span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Original amount</span>
                  <span class="text-gray-200">{{ formatCurrency(Number(pillModal.transaction.debt.amount) || 0) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Remaining balance</span>
                  <span class="text-gray-200">{{ formatCurrency(Number(pillModal.transaction.debt.balance) || 0) }}</span>
                </div>
                <span v-if="pillModal.transaction.debt.is_family_debt" class="inline-block rounded-full bg-blue-900/50 px-2 py-0.5 text-xs text-blue-300">
                  Shared with family
                </span>
              </div>
              <div
                v-if="pillModal.transaction.type === 'income' && pillModal.transaction.is_debt_payment && !isTransactionOwnedByOther(pillModal.transaction)"
                class="rounded-lg border border-emerald-700/30 bg-emerald-950/20 p-3 space-y-2"
              >
                <template v-if="pillModal.transaction.debt_payment_benefit_expense">
                  <p class="text-sm text-gray-300">
                    Also recorded as
                    <span class="font-medium text-emerald-200">
                      {{ pillModal.transaction.debt_payment_benefit_expense.category?.name || 'expense' }}
                    </span>
                  </p>
                  <button
                    type="button"
                    class="w-full rounded-lg bg-emerald-700 hover:bg-emerald-600 text-white text-sm font-medium py-2.5"
                    :disabled="isSelectedMonthLocked"
                    @click="openBenefitForm(pillModal.transaction); closePillModal()"
                  >
                    Edit recorded expense
                  </button>
                </template>
                <template v-else>
                  <p class="text-sm text-gray-400">
                    If this repayment covered a cost for you (like rent), you can also record it as an expense.
                  </p>
                  <button
                    type="button"
                    class="w-full rounded-lg bg-emerald-700 hover:bg-emerald-600 text-white text-sm font-medium py-2.5 disabled:opacity-50"
                    :disabled="isSelectedMonthLocked"
                    @click="openBenefitForm(pillModal.transaction); closePillModal()"
                  >
                    Record as expense
                  </button>
                </template>
              </div>
            </template>
            <p v-else-if="pillModal.type === 'debt'" class="text-sm text-gray-400">No debt details available.</p>

            <template v-if="pillModal.type === 'repayment-covered'">
              <p class="text-sm text-gray-400">
                This income repays expenses on behalf of
                <span class="font-medium text-gray-200">
                  {{ pillModal.transaction.repayment_links?.[0]?.repaid_user?.name ?? 'a family member' }}
                </span>
              </p>
              <div
                v-for="link in (pillModal.transaction.repayment_links ?? [])"
                :key="link.repaid_transaction_id"
                class="rounded-lg border border-gray-700 bg-gray-800/80 px-3 py-2.5 flex items-center justify-between gap-3"
              >
                <div class="min-w-0">
                  <p class="text-sm font-medium text-gray-200">
                    <span v-if="link.repaid_transaction?.category?.icon">{{ link.repaid_transaction.category.icon }} </span>
                    {{ link.repaid_transaction?.category?.name || 'Uncategorized' }}
                  </p>
                  <p class="text-xs text-gray-400">{{ link.repaid_transaction?.transaction_date || '—' }}</p>
                  <p v-if="link.repaid_transaction?.description" class="text-xs text-gray-500 truncate">
                    {{ link.repaid_transaction.description }}
                  </p>
                </div>
                <span class="shrink-0 font-semibold text-red-400 tabular-nums">
                  −{{ formatCurrency(Number(link.amount) || 0) }}
                </span>
              </div>
              <p v-if="!pillModal.transaction.repayment_links?.length" class="text-sm text-gray-400">
                No linked expenses found.
              </p>
            </template>

            <template v-if="pillModal.type === 'repaid-by'">
              <div v-if="pillModal.transaction.repaid_by_link" class="rounded-lg border border-gray-700 bg-gray-800/80 p-3 space-y-2">
                <p class="text-sm text-gray-400">
                  Repaid by
                  <span class="font-medium text-gray-200">
                    {{ pillModal.transaction.repaid_by_link.repayment_transaction?.user?.name ?? 'family member' }}
                  </span>
                </p>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Date repaid</span>
                  <span class="text-gray-200">{{ pillModal.transaction.repaid_by_link.repayment_transaction?.transaction_date || '—' }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Amount</span>
                  <span class="text-green-400 font-medium">+{{ formatCurrency(Number(pillModal.transaction.repaid_by_link.amount) || 0) }}</span>
                </div>
                <p v-if="pillModal.transaction.repaid_by_link.repayment_transaction?.description" class="text-xs text-gray-500">
                  {{ pillModal.transaction.repaid_by_link.repayment_transaction.description }}
                </p>
              </div>
              <p v-else class="text-sm text-gray-400">No repayment details available.</p>
            </template>

            <template v-if="pillModal.type === 'reimbursed-externally'">
              <p class="text-sm text-gray-400">
                This expense was reimbursed by the following income transaction:
              </p>
              <div
                v-if="pillModal.transaction.repaid_by_link?.repayment_transaction"
                class="rounded-lg border border-gray-700 bg-gray-800/80 px-3 py-2.5 space-y-2"
              >
                <p class="text-sm font-medium text-gray-200">
                  <span v-if="pillModal.transaction.repaid_by_link.repayment_transaction.category?.icon">
                    {{ pillModal.transaction.repaid_by_link.repayment_transaction.category.icon }}
                  </span>
                  {{ pillModal.transaction.repaid_by_link.repayment_transaction.category?.name || 'Uncategorized' }}
                </p>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Date</span>
                  <span class="text-gray-200">
                    {{ pillModal.transaction.repaid_by_link.repayment_transaction.transaction_date || '—' }}
                  </span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-gray-400">Amount applied</span>
                  <span class="text-green-400 font-medium tabular-nums">
                    +{{ formatCurrency(Number(pillModal.transaction.repaid_by_link.amount) || 0) }}
                  </span>
                </div>
                <p
                  v-if="pillModal.transaction.repaid_by_link.repayment_transaction.description"
                  class="text-xs text-gray-500"
                >
                  {{ pillModal.transaction.repaid_by_link.repayment_transaction.description }}
                </p>
              </div>
              <p v-else class="text-sm text-gray-400">No linked income transaction found.</p>
            </template>

            <template v-if="pillModal.type === 'external-reimbursement'">
              <p class="text-sm text-gray-400">
                This income reimburses the following expense{{ (pillModal.transaction.repayment_links?.length ?? 0) === 1 ? '' : 's' }}:
              </p>
              <div
                v-for="link in (pillModal.transaction.repayment_links ?? [])"
                :key="link.repaid_transaction_id"
                class="rounded-lg border border-gray-700 bg-gray-800/80 px-3 py-2.5 flex items-center justify-between gap-3"
              >
                <div class="min-w-0">
                  <p class="text-sm font-medium text-gray-200">
                    <span v-if="link.repaid_transaction?.category?.icon">{{ link.repaid_transaction.category.icon }} </span>
                    {{ link.repaid_transaction?.category?.name || 'Uncategorized' }}
                  </p>
                  <p class="text-xs text-gray-400">{{ link.repaid_transaction?.transaction_date || '—' }}</p>
                  <p v-if="link.repaid_transaction?.description" class="text-xs text-gray-500 truncate">
                    {{ link.repaid_transaction.description }}
                  </p>
                </div>
                <span class="shrink-0 font-semibold text-red-400 tabular-nums">
                  −{{ formatCurrency(Number(link.amount) || 0) }}
                </span>
              </div>
              <p v-if="!pillModal.transaction.repayment_links?.length" class="text-sm text-gray-400">
                No linked expenses found.
              </p>
            </template>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="bankPillModal"
        class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center p-0 sm:p-4"
      >
        <div class="absolute inset-0 bg-black/60" @click="closeBankPillModal" />
        <div
          class="relative flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-t-2xl border border-gray-700 bg-gray-900 shadow-xl sm:rounded-2xl"
          role="dialog"
          aria-modal="true"
        >
          <div class="flex shrink-0 items-center justify-between border-b border-gray-800 px-4 py-3">
            <h2 class="text-lg font-semibold text-white">Bank Import</h2>
            <button type="button" class="rounded p-1 text-gray-400 hover:bg-gray-800 hover:text-white" @click="closeBankPillModal">
              <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="min-h-0 overflow-y-auto p-4 space-y-4">
            <div class="rounded-lg border border-gray-700 bg-gray-800/80 p-3 space-y-1.5">
              <p class="text-sm font-semibold text-white">
                🏦 {{ bankPillModal.transaction.plaid_pending_import?.plaid_item?.institution_name }}
              </p>
              <div class="flex justify-between text-sm">
                <span class="text-gray-400">Import date</span>
                <span class="text-gray-200">{{ bankPillModal.transaction.plaid_pending_import?.date || '—' }}</span>
              </div>
              <div class="flex justify-between text-sm">
                <span class="text-gray-400">Import amount</span>
                <span class="text-gray-200">{{ formatCurrency(Math.abs(Number(bankPillModal.transaction.plaid_pending_import?.amount) || 0)) }}</span>
              </div>
              <div v-if="bankPillModal.transaction.plaid_pending_import?.merchant_name" class="flex justify-between text-sm">
                <span class="text-gray-400">Merchant</span>
                <span class="text-gray-200 truncate ml-2 max-w-[55%]">{{ bankPillModal.transaction.plaid_pending_import.merchant_name }}</span>
              </div>
              <div class="flex justify-between text-sm">
                <span class="text-gray-400">Status</span>
                <span
                  class="font-medium"
                  :class="bankPillModal.transaction.plaid_pending_import?.status === 'confirmed' ? 'text-green-400' : 'text-yellow-400'"
                >
                  {{ bankPillModal.transaction.plaid_pending_import?.status || '—' }}
                </span>
              </div>
            </div>

            <div>
              <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">
                Linked transactions ({{ getSiblingTransactions(bankPillModal.transaction).length }})
              </h3>
              <div class="space-y-2">
                <div
                  v-for="sibling in getSiblingTransactions(bankPillModal.transaction)"
                  :key="sibling.id"
                  class="rounded-lg border border-gray-700 bg-gray-800/80 px-3 py-2 flex items-center justify-between gap-2"
                >
                  <div class="min-w-0">
                    <p class="text-sm text-gray-200 font-medium truncate">
                      <span v-if="sibling.category?.icon">{{ sibling.category.icon }} </span>
                      {{ sibling.category?.name || (sibling.is_debt_payment ? 'Debt payment' : 'Uncategorized') }}
                    </p>
                    <p class="text-xs text-gray-400">{{ sibling.transaction_date }}</p>
                  </div>
                  <span
                    class="shrink-0 font-semibold tabular-nums text-sm"
                    :class="sibling.type === 'income' ? 'text-green-400' : 'text-red-400'"
                  >
                    {{ sibling.type === 'income' ? '+' : '−' }}{{ formatCurrency(Number(sibling.amount) || 0) }}
                  </span>
                </div>
              </div>
            </div>

            <div v-if="bankPillModal.transaction.plaid_pending_import?.status === 'confirmed'">
              <p v-if="bankPillUndoError" class="text-sm text-red-400 mb-2">{{ bankPillUndoError }}</p>
              <button
                type="button"
                class="w-full rounded-xl border border-red-700/50 bg-red-900/20 px-4 py-3 text-sm font-semibold text-red-400 transition-colors hover:bg-red-900/40 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="bankPillUndoing"
                @click="handleUndoBankImport"
              >
                {{ bankPillUndoing ? 'Undoing…' : 'Undo Import — Return to Review Queue' }}
              </button>
              <p class="mt-1 text-center text-xs text-gray-500">
                This will delete the linked transactions and put the bank import back in your review queue.
              </p>
            </div>
            <p v-else class="text-center text-xs text-gray-500">
              This import is not confirmed — manage it in Bank Connections → Import Review.
            </p>
          </div>
        </div>
      </div>
    </Teleport>

    <DebtPaymentBenefitForm
      :income="benefitFormIncome"
      :categories="categories"
      :family-users="familyUsers"
      :funds="funds"
      @close="benefitFormIncome = null"
      @saved="handleBenefitSaved"
      @removed="handleBenefitRemoved"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useApi } from '../composables/useApi';
import TransactionForm from '../components/TransactionForm.vue';
import DebtPaymentBenefitForm from '../components/DebtPaymentBenefitForm.vue';
import { debtPaymentCategoryLine } from '../support/debtPaymentLabel.js';
import { closeoutFundName, isCloseoutFundMovement } from '../support/closeoutFundMovement.js';
import { closeoutFundTypeBarStyle, transactionTypeBarStyle } from '../support/transactionTypeBar.js';
import { useSelectedMonth } from '../composables/useSelectedMonth';
import { buildQuickSelectMonths, parseYearMonth } from '../support/yearMonth.js';

const router = useRouter();
const route = useRoute();
const { get, put, del, post, loading, error } = useApi();
const { selectedMonth, setSelectedMonth } = useSelectedMonth();

const transactions = ref([]);
const categories = ref([]);
const familyUsers = ref([]);
const funds = ref([]);
const debtsPayload = ref({ owed: [], owing: [], family_debts: [] });
const showForm = ref(false);
const editingTransactionId = ref(null);
const confirmDelete = ref({});
const selectedMonthFilter = ref('');
const customStartDate = ref('');
const customEndDate = ref('');
const closeoutStatus = ref(null);
const closedMonths = ref([]);
const currentUser = ref(null);
const splitDetailModalTransaction = ref(null);
const pillModal = ref(null);
const bankPillModal = ref(null);
const bankPillUndoing = ref(false);
const bankPillUndoError = ref('');
/** Split IOUs from `GET /month-summary` (`member_balances`) for the selected calendar month only. */
const monthSplitBalances = ref([]);
const editReturnScrollY = ref(null);
const benefitFormIncome = ref(null);

function navigateToMonthSummary() {
  if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
    router.push(`/month-summary/${selectedMonthFilter.value}`);
  }
}

function handleTransactionCreatedFromFab(event) {
  void reloadCurrentFilterData();
}

const quickSelectMonths = computed(() => buildQuickSelectMonths());

function syncMonthQuery(monthValue) {
  const normalizedMonth = parseYearMonth(monthValue);
  const currentMonthQuery = parseYearMonth(route.query.month);

  if (normalizedMonth === currentMonthQuery) {
    return;
  }

  const nextQuery = { ...route.query };
  if (normalizedMonth) {
    nextQuery.month = normalizedMonth;
  } else {
    delete nextQuery.month;
  }

  router.replace({ query: nextQuery });
}

function applyMonthSelection(monthValue) {
  selectedMonthFilter.value = monthValue;
  setSelectedMonth(monthValue);
  const [startDate, endDate] = getMonthDateRange(monthValue);
  fetchData(startDate, endDate);
}

function initializeMonthFilterFromQuery() {
  const monthFromQuery = parseYearMonth(route.query.month);
  const resolvedMonth = monthFromQuery || selectedMonth.value;
  applyMonthSelection(resolvedMonth);

  if (!monthFromQuery) {
    syncMonthQuery(resolvedMonth);
  }
}

onMounted(async () => {
  await fetchCurrentUser();
  initializeMonthFilterFromQuery();
  fetchClosedMonths();
  window.addEventListener('transaction-created', handleTransactionCreatedFromFab);
});

watch(
  () => route.query.month,
  (monthQueryValue) => {
    const monthFromQuery = parseYearMonth(monthQueryValue);
    if (selectedMonthFilter.value === 'custom' && !monthFromQuery) {
      return;
    }
    const resolvedMonth = monthFromQuery || selectedMonth.value;
    if (resolvedMonth !== selectedMonthFilter.value) {
      applyMonthSelection(resolvedMonth);
    }
    if (!monthFromQuery) {
      syncMonthQuery(resolvedMonth);
    }
  }
);

async function fetchCurrentUser() {
  try {
    currentUser.value = await get('/user');
  } catch (err) {
    console.error('Failed to fetch current user:', err);
  }
}

function getMonthDateRange(yearMonth) {
  const [year, month] = yearMonth.split('-').map(Number);
  const startDate = `${yearMonth}-01`;
  const lastDay = new Date(year, month, 0).getDate();
  const endDate = `${yearMonth}-${String(lastDay).padStart(2, '0')}`;
  return [startDate, endDate];
}

async function fetchData(startDate = null, endDate = null) {
  try {
    const params = new URLSearchParams();
    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    if (isFamilyExpenseView.value) params.append('view', 'family');
    const query = params.toString() ? `?${params.toString()}` : '';

    const [txData, catData, usersData, fundsData, debtsData] = await Promise.all([
      get(`/transactions${query}`),
      get('/categories'),
      get('/family/users'),
      get('/funds'),
      get('/debts'),
    ]);
    transactions.value = txData;
    categories.value = catData;
    familyUsers.value = usersData;
    funds.value = fundsData;
    debtsPayload.value =
      debtsData && typeof debtsData === 'object' ? debtsData : debtsPayload.value;

    // Fetch closeout status for current month
    if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
      const [year, month] = selectedMonthFilter.value.split('-').map(Number);
      try {
        const status = await post('/closeout/status', { year, month });
        closeoutStatus.value = status;
      } catch (err) {
        console.error('Failed to fetch closeout status:', err);
      }
    }

    await fetchMonthSplitBalances();
  } catch (err) {
    console.error('Failed to fetch data:', err);
  }
}

async function fetchMonthSplitBalances() {
  if (!currentUser.value?.family_id || !selectedMonthFilter.value || selectedMonthFilter.value === 'custom') {
    monthSplitBalances.value = [];

    return;
  }

  const [year, month] = selectedMonthFilter.value.split('-').map(Number);

  try {
    const summary = await get(`/month-summary?year=${year}&month=${month}`);
    const rows = summary?.member_balances;
    monthSplitBalances.value = Array.isArray(rows) ? rows : [];
  } catch (err) {
    console.error('Failed to fetch month split balances:', err);
    monthSplitBalances.value = [];
  }
}

async function fetchClosedMonths() {
  try {
    const months = await get('/closeout/closed-months');
    closedMonths.value = months;
  } catch (err) {
    console.error('Failed to fetch closed months:', err);
  }
}

function handleMonthFilterChange() {
  if (selectedMonthFilter.value === 'custom') {
    monthSplitBalances.value = [];
    const today = new Date();
    customStartDate.value = today.toISOString().split('T')[0];
    customEndDate.value = today.toISOString().split('T')[0];
    syncMonthQuery(null);
  } else {
    setSelectedMonth(selectedMonthFilter.value);
    syncMonthQuery(selectedMonthFilter.value);
    const [startDate, endDate] = getMonthDateRange(selectedMonthFilter.value);
    fetchData(startDate, endDate);
  }
}

function applyCustomRange() {
  if (customStartDate.value && customEndDate.value) {
    fetchData(customStartDate.value, customEndDate.value);
  }
}

function isSplitListRow(transaction) {
  return Boolean(transaction?.splits?.length);
}

function usesReimbursementCardStyle(transaction) {
  return Boolean(
    transaction?.is_repaid
    || transaction?.is_repayment
    || transaction?.is_repayment_mirror
  );
}

function transactionRowBackgroundClass(transaction) {
  if (transaction?.is_closeout_initiated) {
    return 'bg-purple-900/10';
  }
  if (isExternalReimbursementIncome(transaction)) {
    return 'bg-gray-800';
  }
  if (transaction?.is_repayment) {
    return 'bg-cyan-950/10';
  }
  if (transaction?.is_repayment_mirror) {
    return 'bg-amber-950/10';
  }

  return 'bg-gray-800';
}

function isExternalReimbursementIncome(transaction) {
  if (!transaction?.is_repayment) {
    return false;
  }

  const links = transaction.repayment_links ?? transaction.repaymentLinks ?? [];

  return links.some((link) => Boolean(link?.is_external_repayment));
}

function dayTransactionGroupRank(transaction) {
  if (isSplitListRow(transaction)) {
    return 1;
  }
  if (isTransactionOwnedByOther(transaction)) {
    return 2;
  }

  return 0;
}

function dayTransactionPayerRank(transaction) {
  return isTransactionOwnedByOther(transaction) ? 1 : 0;
}

function isUnassociatedFamilySolo(transaction) {
  return isTransactionOwnedByOther(transaction) && !isSplitListRow(transaction);
}

function isFirstUnassociatedFamilySoloOfDay(dayGroup, transaction) {
  const txs = dayGroup?.transactions || [];
  const firstUnassociated = txs.find((row) => isUnassociatedFamilySolo(row));
  if (!firstUnassociated || Number(firstUnassociated.id) !== Number(transaction?.id)) {
    return false;
  }

  return txs.some((row) => !isUnassociatedFamilySolo(row));
}

function dayTransactionTypeRank(transaction) {
  return transaction?.type === 'income' ? 0 : 1;
}

function currentUserSplitAmount(transaction) {
  const uid = currentUser.value?.id;
  if (uid == null || !transaction?.splits?.length) {
    return null;
  }
  const row = transaction.splits.find(s => Number(s.user_id) === Number(uid));
  if (!row) {
    return null;
  }
  return Number(row.amount) || 0;
}

function expenseAmountForViewerTotals(transaction) {
  if (transaction.type !== 'expense') {
    return Number(transaction.amount) || 0;
  }
  if (isSplitListRow(transaction)) {
    return currentUserSplitAmount(transaction) ?? 0;
  }
  if (isTransactionOwnedByOther(transaction)) {
    return 0;
  }
  return Number(transaction.amount) || 0;
}

function isInterMemberDebtPaymentExpense(transaction) {
  return transaction?.type === 'expense'
    && Boolean(transaction?.is_debt_payment)
    && transaction?.debt?.creditor_id != null;
}

function expenseAmountForFamilyTotals(transaction) {
  if (transaction.type !== 'expense' || transaction.is_repaid) {
    return 0;
  }
  if (isInterMemberDebtPaymentExpense(transaction)) {
    return 0;
  }
  return Number(transaction.amount) || 0;
}

function normalizeSortText(value) {
  return String(value ?? '').trim().toLocaleLowerCase();
}

function transactionCategorySortKey(transaction) {
  return normalizeSortText(getTransactionCategoryLabel(transaction));
}

function transactionAlphabeticalSortKey(transaction) {
  const description = normalizeSortText(transaction?.description);
  if (description) {
    return description;
  }

  return transactionCategorySortKey(transaction);
}

const transactionsByDay = computed(() => {
  const grouped = {};

  ledgerTransactions.value.forEach(tx => {
    const date = tx.transaction_date;
    if (!grouped[date]) {
        grouped[date] = {
          date,
          transactions: [],
          youTotalIncome: 0,
          youTotalExpenses: 0,
          familyTotalIncome: 0,
          familyTotalExpenses: 0,
        };
      }
      grouped[date].transactions.push(tx);
      if (tx.type === 'income') {
        if (!tx.is_debt_payment && !tx.is_repayment) {
          grouped[date].familyTotalIncome += Number(tx.amount) || 0;
          if (!isTransactionOwnedByOther(tx)) {
            grouped[date].youTotalIncome += Number(tx.amount) || 0;
          }
        }
      } else if (!tx.is_repaid) {
        grouped[date].familyTotalExpenses += expenseAmountForFamilyTotals(tx);
        grouped[date].youTotalExpenses += expenseAmountForViewerTotals(tx);
      }
  });

  return Object.values(grouped)
    .map(dayGroup => ({
      ...dayGroup,
      transactions: [...dayGroup.transactions].sort((a, b) => {
        const groupCompare = dayTransactionGroupRank(a) - dayTransactionGroupRank(b);
        if (groupCompare !== 0) {
          return groupCompare;
        }

        const payerCompare = dayTransactionPayerRank(a) - dayTransactionPayerRank(b);
        if (payerCompare !== 0) {
          return payerCompare;
        }

        if (!isTransactionOwnedByOther(a) && !isTransactionOwnedByOther(b)) {
          const typeCompare = dayTransactionTypeRank(a) - dayTransactionTypeRank(b);
          if (typeCompare !== 0) {
            return typeCompare;
          }
        }

        const categoryCompare = transactionCategorySortKey(a).localeCompare(transactionCategorySortKey(b));
        if (categoryCompare !== 0) {
          return categoryCompare;
        }

        const alphabeticalCompare = transactionAlphabeticalSortKey(a).localeCompare(transactionAlphabeticalSortKey(b));
        if (alphabeticalCompare !== 0) {
          return alphabeticalCompare;
        }

        return Number(b.id || 0) - Number(a.id || 0);
      }),
    }))
    .sort((a, b) => parseDateStringAsLocal(b.date) - parseDateStringAsLocal(a.date));
});

const isFamilyExpenseView = computed(() => Boolean(currentUser.value?.view_family_expenses));

const ledgerTransactions = computed(() =>
  transactions.value.filter((tx) => !isCloseoutFundMovement(tx)),
);

const closeoutFundMovementTransactions = computed(() =>
  transactions.value
    .filter((tx) => isCloseoutFundMovement(tx))
    .sort((a, b) => {
      const payerCompare = dayTransactionPayerRank(a) - dayTransactionPayerRank(b);
      if (payerCompare !== 0) {
        return payerCompare;
      }

      const nameCompare = closeoutFundName(a).localeCompare(closeoutFundName(b));
      if (nameCompare !== 0) {
        return nameCompare;
      }

      return Number(a.id || 0) - Number(b.id || 0);
    }),
);

const totalIncome = computed(() => {
  return ledgerTransactions.value
    .filter(tx => tx.type === 'income' && !tx.is_debt_payment && !tx.is_repayment && !isTransactionOwnedByOther(tx))
    .reduce((sum, tx) => sum + (Number(tx.amount) || 0), 0);
});

const familyTotalIncome = computed(() => {
  return ledgerTransactions.value
    .filter(tx => tx.type === 'income' && !tx.is_debt_payment && !tx.is_repayment)
    .reduce((sum, tx) => sum + (Number(tx.amount) || 0), 0);
});

const totalExpenses = computed(() => {
  return ledgerTransactions.value
    .filter(tx => tx.type === 'expense' && !tx.is_repaid)
    .reduce((sum, tx) => sum + expenseAmountForViewerTotals(tx), 0);
});

const familyTotalExpenses = computed(() => {
  return ledgerTransactions.value
    .filter(tx => tx.type === 'expense' && !tx.is_repaid)
    .reduce((sum, tx) => sum + expenseAmountForFamilyTotals(tx), 0);
});

const totalNonNecessityExpenses = computed(() => {
  return ledgerTransactions.value
    .filter(tx => tx.type === 'expense' && tx.is_non_necessity && !tx.is_closeout_initiated)
    .reduce((sum, tx) => sum + expenseAmountForViewerTotals(tx), 0);
});

const hasNonNecessityExpenses = computed(() => totalNonNecessityExpenses.value > 0.005);

const totalNecessityExpenses = computed(() => totalExpenses.value - totalNonNecessityExpenses.value);

const isCurrentMonthHardClosed = computed(() => {
  return closeoutStatus.value?.hard_close != null;
});

const isSelectedMonthLocked = computed(() => {
  return isCurrentMonthHardClosed.value || isUserSoftClosed.value;
});

function isMonthClosed(year, month) {
  return closedMonths.value.some(m => m.year === year && m.month === month);
}

const currentMonthYear = computed(() => {
  if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
    const [year, month] = selectedMonthFilter.value.split('-').map(Number);
    return { year, month };
  }
  return null;
});

const isUserSoftClosed = computed(() => {
  if (!closeoutStatus.value) return false;
  const myClose = closeoutStatus.value.soft_closes?.find(sc => sc.user_id === currentUser.value?.id);
  return !!myClose;
});

/** True when the loaded list has at least one row (filter is a single calendar month, so rows are for that month). */
const selectedMonthHasTransactions = computed(() => transactions.value.length > 0);

const showCloseOutHeaderButton = computed(() => {
  if (!currentMonthYear.value || isCurrentMonthHardClosed.value) {
    return false;
  }
  if (isUserSoftClosed.value) {
    return true;
  }

  return selectedMonthHasTransactions.value;
});

const monthLockUi = computed(() => {
  if (isCurrentMonthHardClosed.value) {
    return {
      borderClass: 'border-amber-600/50',
      title: 'Month is hard-closed — transactions are locked.',
    };
  }
  if (isUserSoftClosed.value) {
    return {
      borderClass: 'border-blue-600/40',
      title: 'You have closed out this month. Use Undo in the header to reopen it for yourself.',
    };
  }
  return {
    borderClass: 'border-gray-700',
    title: 'This month is open for you — use Close Out in the header when you are done.',
  };
});

function formatDate(dateStr) {
  return parseDateStringAsLocal(dateStr).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: parseDateStringAsLocal(dateStr).getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined,
  });
}

function parseDateStringAsLocal(dateStr) {
  const [year, month, day] = String(dateStr).split('T')[0].split('-').map(Number);
  return new Date(year, month - 1, day);
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
  }).format(amount);
}

function transactionPayerDisplayLabel(transaction) {
  const uid = currentUser.value?.id;
  if (uid != null && Number(transaction.user_id) === Number(uid)) {
    return 'You';
  }
  return transaction.user?.name || 'Unknown';
}

function isTransactionOwnedByOther(transaction) {
  const uid = currentUser.value?.id;
  if (uid == null || transaction?.user_id == null) {
    return false;
  }

  return Number(transaction.user_id) !== Number(uid);
}

function isFamilyViewOtherMemberRow(transaction) {
  return isFamilyExpenseView.value && isTransactionOwnedByOther(transaction);
}

/**
 * Small attribute pills on each row (debt repayment, advance, non-necessity, borrow, closeout).
 * Split expenses list each member’s share beside the amount with Total underneath, not a title-row pill.
 * @param {object} tx
 * @returns {{ key: string, label: string, classes: string, title?: string, onClick?: boolean }[]}
 */
function transactionKindPills(tx) {
  if (!tx) {
    return [];
  }

  /** @type {{ key: string, label: string, classes: string, title?: string, onClick?: boolean }[]} */
  const pills = [];

  if (tx.type === 'income' && tx.is_borrow) {
    pills.push({
      key: 'borrow',
      label: 'Borrow',
      classes: 'bg-orange-900/55 text-orange-200',
      title: 'Income from borrowing against a personal or family fund',
    });
  }

  if (tx.type === 'expense' && tx.advance_fund_id) {
    const fundName = tx.advanceFund?.name?.trim();
    pills.push({
      key: 'advance',
      label: 'Advance',
      classes: 'bg-amber-900/55 text-amber-200',
      title: fundName ? `Advances against: ${fundName}` : 'Advances against a fund at closeout',
    });
  }

  if (tx.type === 'expense' && tx.is_non_necessity) {
    pills.push({
      key: 'non-necessity',
      label: 'Non-necessity',
      classes: 'bg-violet-900/55 text-violet-200',
      title: 'Excluded from closeout necessity-expense basis',
    });
  }

  if (tx.is_debt_payment && tx.type === 'expense') {
    pills.push({
      key: 'debt-payment',
      label: 'Debt payment',
      classes: 'bg-sky-900/55 text-sky-200 cursor-pointer hover:bg-sky-800/70',
      onClick: true,
    });
  }

  if (tx.is_debt_payment && tx.type === 'income') {
    pills.push({
      key: 'repayment',
      label: 'Repayment',
      classes: 'bg-sky-900/55 text-sky-200 cursor-pointer hover:bg-sky-800/70',
      onClick: true,
    });
  }

  if (tx.is_closeout_initiated && !isCloseoutFundMovement(tx)) {
    pills.push({
      key: 'closeout',
      label: 'Closeout',
      classes: 'bg-purple-900/60 text-purple-200',
      title: 'Generated by closeout or title completion',
    });
  }

  return pills;
}

function getTransactionCategoryLabel(transaction) {
  if (transaction.is_debt_payment) {
    return debtPaymentCategoryLine(transaction);
  }
  return transaction.category?.name || 'Uncategorized';
}

function openSplitDetailModal(transaction) {
  if (!transaction?.splits?.length) {
    return;
  }
  splitDetailModalTransaction.value = transaction;
}

function closeSplitDetailModal() {
  splitDetailModalTransaction.value = null;
}

function openPillModal(type, transaction) {
  pillModal.value = { type, transaction };
}

function closePillModal() {
  pillModal.value = null;
}

function canRecordDebtPaymentBenefit(transaction) {
  return Boolean(
    transaction?.is_debt_payment
    && transaction?.type === 'income'
    && !transaction?.debt_payment_benefit_expense
    && !isSelectedMonthLocked.value
    && currentUser.value?.id != null
    && Number(transaction.user_id) === Number(currentUser.value.id),
  );
}

function openBenefitForm(incomeTransaction) {
  if (!incomeTransaction || isSelectedMonthLocked.value || isTransactionOwnedByOther(incomeTransaction)) {
    return;
  }
  benefitFormIncome.value = incomeTransaction;
}

function openBenefitFormFromBenefit(benefitTransaction) {
  const income = benefitTransaction?.debt_payment_income
    || getTransactionById(benefitTransaction?.debt_payment_income_id);
  if (income) {
    openBenefitForm(income);
    return;
  }
  // Fallback: open with a synthetic income shape if the income row is not in the current filter.
  if (benefitTransaction?.debt_payment_income_id) {
    benefitFormIncome.value = {
      id: benefitTransaction.debt_payment_income_id,
      amount: benefitTransaction.amount,
      transaction_date: benefitTransaction.transaction_date,
      description: benefitTransaction.description,
      debt_payment_benefit_expense: benefitTransaction,
    };
  }
}

async function handleBenefitSaved() {
  benefitFormIncome.value = null;
  await reloadCurrentFilterData();
}

async function handleBenefitRemoved() {
  benefitFormIncome.value = null;
  await reloadCurrentFilterData();
}

function getSiblingTransactions(transaction) {
  const importId = transaction.plaid_pending_import?.id ?? transaction.plaid_pending_import_id;
  if (!importId) {
    return [transaction];
  }

  return transactions.value.filter(
    (t) => t.plaid_pending_import?.id === importId || t.plaid_pending_import_id === importId,
  );
}

function openBankPillModal(transaction) {
  bankPillModal.value = { transaction };
  bankPillUndoing.value = false;
  bankPillUndoError.value = '';
}

function closeBankPillModal() {
  bankPillModal.value = null;
  bankPillUndoing.value = false;
  bankPillUndoError.value = '';
}

async function handleUndoBankImport() {
  if (!bankPillModal.value) {
    return;
  }
  const importId = bankPillModal.value.transaction.plaid_pending_import?.id;
  if (!importId) {
    return;
  }
  bankPillUndoing.value = true;
  bankPillUndoError.value = '';
  try {
    await post(`/plaid/pending-imports/${importId}/undo-confirm`, {});
    closeBankPillModal();
    await reloadCurrentFilterData();
  } catch (err) {
    bankPillUndoError.value = err.response?.data?.message || 'Could not undo this import. The month may be closed.';
  } finally {
    bankPillUndoing.value = false;
  }
}

function splitsSortedForModal(transaction) {
  if (!transaction?.splits?.length) {
    return [];
  }
  const uid = currentUser.value?.id;
  return [...transaction.splits].sort((a, b) => {
    const aMine = uid != null && Number(a.user_id) === Number(uid);
    const bMine = uid != null && Number(b.user_id) === Number(uid);
    if (aMine !== bMine) {
      return aMine ? -1 : 1;
    }
    const nameA = (a.user?.name || `User ${a.user_id}`).toLowerCase();
    const nameB = (b.user?.name || `User ${b.user_id}`).toLowerCase();
    return nameA.localeCompare(nameB);
  });
}

function isSplitRowForCurrentUser(split) {
  const uid = currentUser.value?.id;
  if (uid == null) {
    return false;
  }
  return Number(split.user_id) === Number(uid);
}

function splitParticipantName(split) {
  return split?.user?.name || 'Member';
}

function splitParticipantLabel(split) {
  if (isSplitRowForCurrentUser(split)) {
    return 'You';
  }

  return splitParticipantName(split);
}

function transactionAmountColorClass(transaction) {
  if (transaction?.type === 'income') {
    return transaction.is_debt_payment ? 'text-sky-400' : 'text-green-400';
  }

  return 'text-red-400';
}

function formatSplitSharePercent(value) {
  const n = Number(value);
  if (Number.isNaN(n)) {
    return '—';
  }
  const rounded = Math.round(n * 100) / 100;
  if (Math.abs(rounded - Math.round(rounded)) < 0.001) {
    return `${Math.round(rounded)}%`;
  }
  return `${rounded.toFixed(2)}%`;
}

function getTransactionById(id) {
  return transactions.value.find(t => t.id === id);
}

function isSystemCloseoutEntry(transaction) {
  if (transaction?.is_closeout_initiated) {
    return true;
  }

  return Boolean(transaction?.is_debt_payment && transaction?.type === 'income');
}

function isTransactionEditLocked(transaction) {
  if (isFamilyViewOtherMemberRow(transaction)) {
    return true;
  }

  if (isSystemCloseoutEntry(transaction)) {
    return true;
  }

  if (transaction?.is_debt_payment_benefit) {
    return true;
  }

  return Boolean(transaction?.is_repaid || transaction?.is_repayment);
}

function deleteButtonTitle(transaction) {
  if (isSelectedMonthLocked.value) {
    return 'Cannot edit transactions in a closed month';
  }
  if (transaction?.is_closeout_initiated) {
    return 'Closeout-generated entries cannot be deleted here';
  }
  if (transaction?.is_debt_payment_benefit) {
    return 'Remove this expense from the linked debt repayment instead';
  }
  if (transaction?.is_repayment) {
    if (transaction.repayment_links?.[0]?.is_external_repayment) {
      return 'Deleting this will unlink it from the reimbursed expense(s), and those expenses will count toward your budget again.';
    }

    return "Deleting this will remove the repayment links and the linked family member's mirror expense.";
  }

  return 'Delete';
}

async function handleTransactionCreated(transaction) {
  await reloadCurrentFilterData();
  showForm.value = false;
}

async function handleTransactionUpdated() {
  const scrollTopBeforeSave = Number.isFinite(editReturnScrollY.value)
    ? editReturnScrollY.value
    : window.scrollY;

  await reloadCurrentFilterData();
  showForm.value = false;
  editingTransactionId.value = null;

  await nextTick();
  window.requestAnimationFrame(() => {
    window.scrollTo({
      top: scrollTopBeforeSave,
      behavior: 'auto',
    });
  });
  editReturnScrollY.value = null;
}

async function reloadCurrentFilterData() {
  if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
    const [startDate, endDate] = getMonthDateRange(selectedMonthFilter.value);
    await fetchData(startDate, endDate);
    return;
  }

  if (customStartDate.value && customEndDate.value) {
    await fetchData(customStartDate.value, customEndDate.value);
    return;
  }

  await fetchData();
}

async function handleDeleteConfirm(transactionId) {
  const tx = getTransactionById(transactionId);
  if (isSelectedMonthLocked.value || isFamilyViewOtherMemberRow(tx)) {
    confirmDelete.value[transactionId] = false;
    return;
  }

  try {
    await del(`/transactions/${transactionId}`);
    confirmDelete.value[transactionId] = false;
    if (selectedMonthFilter.value && selectedMonthFilter.value !== 'custom') {
      const [sd, ed] = getMonthDateRange(selectedMonthFilter.value);
      await fetchData(sd, ed);
    } else if (customStartDate.value && customEndDate.value) {
      await fetchData(customStartDate.value, customEndDate.value);
    } else {
      await fetchData();
    }
  } catch (err) {
    console.error('Failed to delete transaction:', err);
  }
}

function handleFormClose() {
  showForm.value = false;
  editingTransactionId.value = null;
  editReturnScrollY.value = null;
}

function openEditForm(transactionId) {
  const tx = getTransactionById(transactionId);
  if (isSelectedMonthLocked.value || isTransactionEditLocked(tx)) {
    return;
  }
  editReturnScrollY.value = window.scrollY;
  editingTransactionId.value = transactionId;
  showForm.value = true;
}

async function handleSoftClose() {
  try {
    await post('/closeout/soft-close', currentMonthYear.value);
    if (currentMonthYear.value) {
      const status = await post('/closeout/status', currentMonthYear.value);
      closeoutStatus.value = status;
    }
  } catch (err) {
    console.error('Failed to soft close month:', err);
  }
}

async function handleUndoSoftClose() {
  try {
    await post('/closeout/undo-soft-close', currentMonthYear.value);
    if (currentMonthYear.value) {
      const status = await post('/closeout/status', currentMonthYear.value);
      closeoutStatus.value = status;
    }
  } catch (err) {
    console.error('Failed to undo soft close:', err);
  }
}

</script>
