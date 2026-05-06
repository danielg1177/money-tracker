<template>
  <main class="min-h-screen bg-slate-50 text-slate-900 p-4 sm:p-6">
    <div class="mx-auto max-w-md rounded-3xl bg-white p-6 shadow-lg shadow-slate-200/80 sm:p-8">
      <div class="mb-6 text-center">
        <p class="text-sm text-slate-500">Money Tracker</p>
        <h1 class="mt-2 text-3xl font-semibold text-slate-900">Family budgeting made simple</h1>
      </div>

      <template v-if="!authenticated">
        <form @submit.prevent="submitLogin" class="space-y-5">
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700">Email</label>
            <input
              v-model="form.email"
              type="email"
              required
              class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
            />
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700">Password</label>
            <input
              v-model="form.password"
              type="password"
              required
              class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
            />
          </div>

          <button
            type="submit"
            class="inline-flex w-full justify-center rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700"
          >
            Sign in
          </button>

          <p class="text-center text-sm text-slate-500">
            Admin users can create and manage family accounts.
          </p>
        </form>

        <div v-if="error" class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {{ error }}
        </div>
      </template>

      <template v-else>
        <div class="space-y-5">
          <div class="rounded-3xl bg-slate-100 p-5">
            <h2 class="text-xl font-semibold text-slate-900">Welcome back, {{ user?.name }}</h2>
            <p class="mt-2 text-sm text-slate-600">You're signed in and ready to start tracking income, expenses, split debt, and fund allocations.</p>
          </div>

          <template v-if="user?.isAdmin">
            <!-- Admin UI -->
          </template>

          <template v-else>
            <div class="rounded-3xl bg-slate-100 p-5">
              <h3 class="text-lg font-semibold text-slate-900">Family Transactions</h3>
              <p class="mt-2 text-sm text-slate-600">View and add shared transactions.</p>
              <div class="mt-4 grid gap-3">
                <button @click="showTransactions = !showTransactions" class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                  {{ showTransactions ? 'Hide' : 'Show' }} Transactions
                </button>
                <button @click="showCreateTransaction = !showCreateTransaction" class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                  {{ showCreateTransaction ? 'Hide' : 'Add' }} Transaction
                </button>
              </div>
            </div>

            <div v-if="showTransactions" class="rounded-3xl bg-white border border-slate-200 p-5">
              <h4 class="text-md font-semibold text-slate-900">Transactions</h4>
              <ul class="mt-3 space-y-2">
                <li v-for="t in transactions" :key="t.id" class="text-sm text-slate-700 border-b border-slate-100 pb-2">
                  <div class="flex justify-between">
                    <span>{{ t.description || t.category.name }} - ${{ t.amount }}</span>
                    <span class="text-xs text-slate-500">{{ t.user.name }}</span>
                  </div>
                  <div v-if="t.is_split" class="mt-1 text-xs text-slate-600">
                    Split: {{ t.splits.map(s => `${s.user.name}: ${s.percentage}%`).join(', ') }}
                  </div>
                </li>
              </ul>
            </div>

            <div v-if="showCreateTransaction" class="rounded-3xl bg-white border border-slate-200 p-5">
              <h4 class="text-md font-semibold text-slate-900">Add Transaction</h4>
              <form @submit.prevent="createTransaction" class="mt-4 space-y-3">
                <select v-model="newTransaction.category_id" required class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                  <option value="">Select Category</option>
                  <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <input v-model="newTransaction.amount" v-bind="mobileDecimalNumberAttrs" type="number" step="0.01" placeholder="Amount" required class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" />
                <input v-model="newTransaction.description" type="text" placeholder="Description" class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" />
                <label class="flex items-center gap-2 text-sm">
                  <input v-model="newTransaction.is_split" type="checkbox" class="rounded border-slate-300" />
                  Split transaction
                </label>
                <div v-if="newTransaction.is_split" class="space-y-2">
                  <p class="text-sm text-slate-600">Split percentages (must sum to 100%):</p>
                  <div v-for="(split, index) in newTransaction.split_data" :key="index" class="flex gap-2 items-center">
                    <select v-model="split.user_id" required class="flex-1 rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                      <option value="">Select User</option>
                      <option v-for="u in familyUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <input v-model="split.percentage" v-bind="mobileDecimalNumberAttrs" type="number" min="0" max="100" step="0.1" placeholder="%" required class="w-20 rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" />
                    <button @click="removeSplit(index)" type="button" class="text-red-500">×</button>
                  </div>
                  <button @click="addSplit" type="button" class="text-sm text-slate-900 underline">Add Split</button>
                </div>
                <button type="submit" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700">Create Transaction</button>
              </form>
            </div>
          </template>

          <div class="rounded-3xl bg-slate-100 p-5">
            <h3 class="text-lg font-semibold text-slate-900">Funds & Debts</h3>
            <p class="mt-2 text-sm text-slate-600">Manage savings funds and track debts.</p>
            <div class="mt-4 grid gap-3">
              <button @click="showFunds = !showFunds" class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                {{ showFunds ? 'Hide' : 'Show' }} Funds
              </button>
              <button @click="showDebts = !showDebts" class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                {{ showDebts ? 'Hide' : 'Show' }} Debts
              </button>
            </div>
          </div>

          <div v-if="showFunds" class="rounded-3xl bg-white border border-slate-200 p-5">
            <h4 class="text-md font-semibold text-slate-900">Funds</h4>
            <ul class="mt-3 space-y-3">
              <li v-for="f in funds" :key="f.id" class="border-b border-slate-100 pb-3">
                <div class="flex justify-between items-center">
                  <div>
                    <p class="text-sm font-medium text-slate-900">{{ f.name }}</p>
                    <p class="text-xs text-slate-600">Balance: ${{ parseFloat(f.balance).toFixed(2) }}</p>
                  </div>
                  <button @click="toggleFundRules(f)" class="text-xs text-slate-500 underline">
                    {{ expandedFunds.includes(f.id) ? 'Hide' : 'Show' }} Rules
                  </button>
                </div>
                <div v-if="expandedFunds.includes(f.id)" class="mt-2 ml-4">
                  <ul class="space-y-1">
                    <li v-for="r in f.fundRules" :key="r.id" class="text-xs text-slate-600">
                      {{ r.name }} - {{ r.allocation_type === 'percentage' ? r.amount + '%' : '$' + r.amount }}
                    </li>
                  </ul>
                  <button @click="showCreateRuleFor(f)" class="mt-2 text-xs text-slate-900 underline">Add Rule</button>
                </div>
              </li>
            </ul>
            <button @click="showCreateFund = !showCreateFund" class="mt-4 rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700">
              {{ showCreateFund ? 'Cancel' : 'Create Fund' }}
            </button>
            <form v-if="showCreateFund" @submit.prevent="createFund" class="mt-4 space-y-3">
              <input v-model="newFund.name" type="text" placeholder="Fund Name" required class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" />
              <textarea v-model="newFund.description" placeholder="Description" class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"></textarea>
              <button type="submit" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700">Create</button>
            </form>

            <div v-if="creatingRuleFor" class="mt-4 rounded-2xl bg-slate-100 p-4">
              <h5 class="text-sm font-semibold text-slate-900">Create Rule for {{ creatingRuleFor.name }}</h5>
              <form @submit.prevent="createFundRule" class="mt-3 space-y-3">
                <input v-model="newFundRule.name" type="text" placeholder="Rule Name" required class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" />
                <select v-model="newFundRule.allocation_type" required class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                  <option value="">Select Type</option>
                  <option value="percentage">Percentage</option>
                  <option value="fixed">Fixed Amount</option>
                </select>
                <input v-model="newFundRule.amount" v-bind="mobileDecimalNumberAttrs" type="number" step="0.01" placeholder="Amount" required class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" />
                <input v-model="newFundRule.order" v-bind="mobileIntegerNumberAttrs" type="number" placeholder="Order" required class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" />
                <div class="flex gap-2">
                  <button type="submit" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700">Create</button>
                  <button @click="cancelCreateRule" type="button" class="rounded-2xl bg-slate-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-400">Cancel</button>
                </div>
              </form>
            </div>
          </div>

          <div v-if="showDebts" class="rounded-3xl bg-white border border-slate-200 p-5">
            <h4 class="text-md font-semibold text-slate-900">Debts</h4>
            <div v-if="debts.owed.length > 0" class="mt-4">
              <p class="text-sm font-semibold text-slate-900 mb-2">Debts You Owe</p>
              <ul class="space-y-2">
                <li v-for="d in debts.owed" :key="d.id" class="text-sm text-slate-700 border border-red-200 bg-red-50 p-3 rounded-lg">
                  <div class="flex justify-between items-center">
                    <div>
                      <p>{{ d.creditor.name }}: ${{ parseFloat(d.balance).toFixed(2) }}</p>
                      <p class="text-xs text-slate-600">{{ d.description }}</p>
                    </div>
                    <button @click="showPayDebtFor(d)" class="text-xs text-red-600 underline">Pay</button>
                  </div>
                </li>
              </ul>
            </div>
            <div v-if="debts.owing.length > 0" class="mt-4">
              <p class="text-sm font-semibold text-slate-900 mb-2">Debts Owed to You</p>
              <ul class="space-y-2">
                <li v-for="d in debts.owing" :key="d.id" class="text-sm text-slate-700 border border-green-200 bg-green-50 p-3 rounded-lg">
                  <div class="flex justify-between items-center">
                    <div>
                      <p>{{ d.debtor.name }}: ${{ parseFloat(d.balance).toFixed(2) }}</p>
                      <p class="text-xs text-slate-600">{{ d.description }}</p>
                    </div>
                  </div>
                </li>
              </ul>
            </div>

            <div v-if="payingDebt" class="mt-4 rounded-2xl bg-slate-100 p-4">
              <h5 class="text-sm font-semibold text-slate-900">Pay Debt to {{ payingDebt.creditor.name }}</h5>
              <form @submit.prevent="payDebt" class="mt-3 space-y-3">
                <p class="text-sm text-slate-600">Amount owed: ${{ parseFloat(payingDebt.balance).toFixed(2) }}</p>
                <input v-model="paymentAmount" v-bind="mobileDecimalNumberAttrs" type="number" step="0.01" placeholder="Payment Amount" required class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" />
                <input v-model="paymentDescription" type="text" placeholder="Description" class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" />
                <div class="flex gap-2">
                  <button type="submit" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700">Pay</button>
                  <button @click="cancelPayDebt" type="button" class="rounded-2xl bg-slate-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-400">Cancel</button>
                </div>
              </form>
            </div>
          </div>

          <div class="grid gap-3">
            <button @click="logout" class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">Sign out</button>
          </div>
        </div>
      </template>
    </div>
  </main>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { mobileDecimalNumberAttrs, mobileIntegerNumberAttrs } from '../support/mobileNumericInputAttrs.js';

const authenticated = ref(false);
const error = ref('');
const user = ref(null);
const users = ref([]);
const families = ref([]);
const showUsers = ref(false);
const showFamilies = ref(false);
const showCreateUser = ref(false);
const showCreateFamily = ref(false);
const expandedFamilies = ref([]);
const creatingCategoryFor = ref(null);
const transactions = ref([]);
const categories = ref([]);
const familyUsers = ref([]);
const showTransactions = ref(false);
const showCreateTransaction = ref(false);
const funds = ref([]);
const debts = ref({ owed: [], owing: [] });
const showFunds = ref(false);
const showDebts = ref(false);
const showCreateFund = ref(false);
const expandedFunds = ref([]);
const creatingRuleFor = ref(null);
const payingDebt = ref(null);
const paymentAmount = ref('');
const paymentDescription = ref('');

const form = reactive({
  email: '',
  password: '',
});

const newUser = reactive({
  name: '',
  email: '',
  password: '',
  role: 'member',
  family_id: '',
});

const newFamily = reactive({
  name: '',
  description: '',
});

const newCategory = reactive({
  name: '',
  icon: '',
  is_income: true,
  is_expense: false,
  split_percentage: null,
});

const newTransaction = reactive({
  category_id: '',
  amount: '',
  description: '',
  is_split: false,
  split_data: [],
});

const newFund = reactive({
  name: '',
  description: '',
});

const newFundRule = reactive({
  name: '',
  allocation_type: 'percentage',
  amount: '',
  order: 1,
});

const submitLogin = async () => {
  error.value = '';

  try {
    await window.axios.post('/login', {
      email: form.email,
      password: form.password,
    });

    authenticated.value = true;
    await fetchUser();
    if (user.value?.isAdmin) {
      await fetchUsers();
      await fetchFamilies();
    } else {
      await fetchTransactions();
      await fetchCategories();
      await fetchFamilyUsers();
      await fetchFunds();
      await fetchDebts();
    }
  } catch (caught) {
    error.value = caught.response?.data?.message || 'Unable to sign in. Please check your credentials.';
  }
};

const fetchUser = async () => {
  try {
    const response = await window.axios.get('/user');
    user.value = response.data;
  } catch (caught) {
    console.error('Failed to fetch user:', caught);
  }
};

const fetchUsers = async () => {
  try {
    const response = await window.axios.get('/admin/users');
    users.value = response.data;
  } catch (caught) {
    console.error('Failed to fetch users:', caught);
  }
};

const fetchFamilies = async () => {
  try {
    const response = await window.axios.get('/admin/families');
    families.value = response.data;
  } catch (caught) {
    console.error('Failed to fetch families:', caught);
  }
};

const fetchTransactions = async () => {
  try {
    const response = await window.axios.get('/transactions');
    transactions.value = response.data;
  } catch (caught) {
    console.error('Failed to fetch transactions:', caught);
  }
};

const fetchCategories = async () => {
  if (!user.value?.family_id) return;
  try {
    const response = await window.axios.get(`/admin/categories/${user.value.family_id}`);
    categories.value = response.data;
  } catch (caught) {
    console.error('Failed to fetch categories:', caught);
  }
};

const fetchFamilyUsers = async () => {
  try {
    const response = await window.axios.get('/family/users');
    familyUsers.value = response.data;
  } catch (caught) {
    console.error('Failed to fetch family users:', caught);
  }
};

const createUser = async () => {
  try {
    await window.axios.post('/admin/users', newUser);
    newUser.name = '';
    newUser.email = '';
    newUser.password = '';
    newUser.role = 'member';
    newUser.family_id = '';
    showCreateUser.value = false;
    await fetchUsers();
  } catch (caught) {
    console.error('Failed to create user:', caught);
  }
};

const createFamily = async () => {
  try {
    await window.axios.post('/admin/families', newFamily);
    newFamily.name = '';
    newFamily.description = '';
    showCreateFamily.value = false;
    await fetchFamilies();
  } catch (caught) {
    console.error('Failed to create family:', caught);
  }
};

const toggleFamilyCategories = (family) => {
  const index = expandedFamilies.value.indexOf(family.id);
  if (index > -1) {
    expandedFamilies.value.splice(index, 1);
  } else {
    expandedFamilies.value.push(family.id);
  }
};

const showCreateCategoryFor = (family) => {
  creatingCategoryFor.value = family;
  newCategory.name = '';
  newCategory.icon = '';
  newCategory.is_income = true;
  newCategory.is_expense = false;
  newCategory.split_percentage = null;
};

const createCategory = async () => {
  try {
    await window.axios.post('/admin/categories', {
      ...newCategory,
      family_id: creatingCategoryFor.value.id,
    });
    creatingCategoryFor.value = null;
    await fetchFamilies();
  } catch (caught) {
    console.error('Failed to create category:', caught);
  }
};

const cancelCreateCategory = () => {
  creatingCategoryFor.value = null;
};

const addSplit = () => {
  newTransaction.split_data.push({ user_id: '', percentage: 0 });
};

const removeSplit = (index) => {
  newTransaction.split_data.splice(index, 1);
};

const createTransaction = async () => {
  try {
    await window.axios.post('/transactions', newTransaction);
    newTransaction.category_id = '';
    newTransaction.amount = '';
    newTransaction.description = '';
    newTransaction.is_split = false;
    newTransaction.split_data = [];
    showCreateTransaction.value = false;
    await fetchTransactions();
  } catch (caught) {
    console.error('Failed to create transaction:', caught);
  }
};

const fetchFunds = async () => {
  try {
    const response = await window.axios.get('/funds');
    funds.value = response.data;
  } catch (caught) {
    console.error('Failed to fetch funds:', caught);
  }
};

const fetchDebts = async () => {
  try {
    const response = await window.axios.get('/debts');
    debts.value = response.data;
  } catch (caught) {
    console.error('Failed to fetch debts:', caught);
  }
};

const toggleFundRules = (fund) => {
  const index = expandedFunds.value.indexOf(fund.id);
  if (index > -1) {
    expandedFunds.value.splice(index, 1);
  } else {
    expandedFunds.value.push(fund.id);
  }
};

const showCreateRuleFor = (fund) => {
  creatingRuleFor.value = fund;
  newFundRule.name = '';
  newFundRule.allocation_type = 'percentage';
  newFundRule.amount = '';
  newFundRule.order = 1;
};

const cancelCreateRule = () => {
  creatingRuleFor.value = null;
};

const createFund = async () => {
  try {
    await window.axios.post('/funds', newFund);
    newFund.name = '';
    newFund.description = '';
    showCreateFund.value = false;
    await fetchFunds();
  } catch (caught) {
    console.error('Failed to create fund:', caught);
  }
};

const createFundRule = async () => {
  try {
    await window.axios.post('/fund-rules', {
      ...newFundRule,
      fund_id: creatingRuleFor.value.id,
    });
    creatingRuleFor.value = null;
    await fetchFunds();
  } catch (caught) {
    console.error('Failed to create fund rule:', caught);
  }
};

const showPayDebtFor = (debt) => {
  payingDebt.value = debt;
  paymentAmount.value = parseFloat(debt.balance).toFixed(2);
  paymentDescription.value = '';
};

const cancelPayDebt = () => {
  payingDebt.value = null;
  paymentAmount.value = '';
  paymentDescription.value = '';
};

const payDebt = async () => {
  try {
    await window.axios.post('/debts/pay', {
      debt_id: payingDebt.value.id,
      amount: paymentAmount.value,
      description: paymentDescription.value,
    });
    payingDebt.value = null;
    paymentAmount.value = '';
    paymentDescription.value = '';
    await fetchDebts();
    await fetchTransactions();
  } catch (caught) {
    console.error('Failed to pay debt:', caught);
  }
};

const logout = async () => {
  await window.axios.post('/logout');
  authenticated.value = false;
  user.value = null;
  users.value = [];
  families.value = [];
  showUsers.value = false;
  showFamilies.value = false;
  showCreateUser.value = false;
  showCreateFamily.value = false;
  expandedFamilies.value = [];
  creatingCategoryFor.value = null;
  transactions.value = [];
  categories.value = [];
  familyUsers.value = [];
  showTransactions.value = false;
  showCreateTransaction.value = false;
  funds.value = [];
  debts.value = { owed: [], owing: [] };
  showFunds.value = false;
  showDebts.value = false;
  showCreateFund.value = false;
  expandedFunds.value = [];
  creatingRuleFor.value = null;
  payingDebt.value = null;
  paymentAmount.value = '';
  paymentDescription.value = '';
  form.email = '';
  form.password = '';
};
</script>
