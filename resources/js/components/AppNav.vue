<template>
  <div class="flex flex-col min-h-screen">
    <div class="flex-1">
      <slot />
    </div>
    
    <nav class="fixed bottom-0 left-0 right-0 z-30 bg-gray-900 border-t border-gray-800 safe-area-inset-bottom">
      <div class="flex items-center justify-between px-3 py-2">
        <div class="flex gap-1 flex-1">
          <router-link 
            to="/dashboard" 
            class="flex-1 flex flex-col items-center gap-1 py-2 px-2 rounded-lg text-sm font-medium transition-colors"
            :class="isActive('/dashboard') ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z" />
            </svg>
            <span class="hidden sm:block text-xs">Dashboard</span>
          </router-link>

          <router-link 
            to="/transactions" 
            class="flex-1 flex flex-col items-center gap-1 py-2 px-2 rounded-lg text-sm font-medium transition-colors"
            :class="isActive('/transactions') ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z" />
            </svg>
            <span class="hidden sm:block text-xs">Transactions</span>
          </router-link>

          <router-link 
            to="/funds" 
            class="flex-1 flex flex-col items-center gap-1 py-2 px-2 rounded-lg text-sm font-medium transition-colors"
            :class="isActive('/funds') ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" />
            </svg>
            <span class="hidden sm:block text-xs">Funds</span>
          </router-link>

          <router-link 
            to="/closeout-rules" 
            class="flex-1 flex flex-col items-center gap-1 py-2 px-2 rounded-lg text-sm font-medium transition-colors"
            :class="isActive('/closeout-rules') ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v2h16V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd" />
              <path d="M4 11a2 2 0 00-2 2v5a2 2 0 002 2h12a2 2 0 002-2v-5a2 2 0 00-2-2H4z" />
            </svg>
            <span class="hidden sm:block text-xs">Closeout</span>
          </router-link>

          <router-link 
            to="/debts" 
            class="flex-1 flex flex-col items-center gap-1 py-2 px-2 rounded-lg text-sm font-medium transition-colors"
            :class="isActive('/debts') ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M5 2a1 1 0 011 1v1h1a1 1 0 010 2H6v1a1 1 0 01-2 0V6H3a1 1 0 010-2h1V3a1 1 0 011-1zm0 10a1 1 0 011 1v1h1a1 1 0 110 2H6v1a1 1 0 11-2 0v-1H3a1 1 0 110-2h1v-1a1 1 0 011-1zM16 2a1 1 0 011 1v1h1a1 1 0 110 2h-1v1a1 1 0 11-2 0V6h-1a1 1 0 110-2h1V3a1 1 0 011-1z" clip-rule="evenodd" />
            </svg>
            <span class="hidden sm:block text-xs">Debts</span>
          </router-link>

          <router-link 
            to="/categories" 
            class="flex-1 flex flex-col items-center gap-1 py-2 px-2 rounded-lg text-sm font-medium transition-colors"
            :class="isActive('/categories') ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM13 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2h-2zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM13 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2h-2z" />
            </svg>
            <span class="hidden sm:block text-xs">Categories</span>
          </router-link>
        </div>

        <div v-if="user" class="flex items-center gap-2 ml-2 pl-2 border-l border-gray-700">
          <span class="text-sm text-gray-300 font-medium hidden sm:inline">{{ user.name }}</span>
          
          <!-- Admin dropdown: only for admin role -->
          <div v-if="user.isAdmin" class="relative">
            <button 
              @click="showAdminMenu = !showAdminMenu"
              class="flex items-center gap-1 py-2 px-2 rounded-lg text-xs font-medium text-amber-400 hover:bg-gray-800 transition-colors"
            >
              <span>Admin</span>
              <svg class="w-4 h-4" :class="{ 'rotate-180': showAdminMenu }" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </button>

            <div 
              v-if="showAdminMenu"
              class="absolute bottom-full right-0 mb-2 bg-gray-800 border border-gray-700 rounded-lg shadow-lg z-10"
            >
              <router-link 
                to="/admin/users"
                @click="showAdminMenu = false"
                class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition-colors first:rounded-t-lg"
              >
                Users
              </router-link>
              <router-link 
                to="/admin/families"
                @click="showAdminMenu = false"
                class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition-colors last:rounded-b-lg"
              >
                Families
              </router-link>
            </div>
          </div>

          <!-- My Family link: only for head_of_household -->
          <router-link
            v-else-if="user.canManageFamily"
            to="/my-family"
            @click="showAdminMenu = false"
            class="py-2 px-2 rounded-lg text-xs font-medium text-blue-400 hover:bg-gray-800 transition-colors"
          >
            My Family
          </router-link>

          <button 
            @click="handleLogout"
            class="py-2 px-2 rounded-lg text-xs font-medium text-red-400 hover:bg-gray-800 transition-colors"
          >
            Logout
          </button>
        </div>
      </div>
    </nav>

    <!-- Transaction FAB -->
    <button
      v-if="!isAdminPage"
      @click="showTransactionForm = true"
      class="fixed right-3 z-40 w-12 h-12 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg flex items-center justify-center transition-transform hover:scale-110 bottom-[calc(4.25rem+env(safe-area-inset-bottom,0px))] sm:bottom-[calc(6.5rem+env(safe-area-inset-bottom,0px))]"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
      </svg>
    </button>

    <!-- Transaction Form Modal -->
    <Transition
      enter-active-class="transition duration-300"
      enter-from-class="translate-y-full"
      enter-to-class="translate-y-0"
      leave-active-class="transition duration-300"
      leave-from-class="translate-y-0"
      leave-to-class="translate-y-full"
    >
      <div v-if="showTransactionForm" class="fixed inset-0 z-50">
        <!-- Backdrop -->
        <div
          class="absolute inset-0 bg-black/50"
          @click="handleTransactionFormClose"
        />
        <!-- Modal -->
        <div class="absolute bottom-0 left-0 right-0 bg-gray-900 rounded-t-2xl max-h-[85vh] overflow-y-auto">
          <div class="sticky top-0 border-b border-gray-800 px-4 py-4 bg-gray-900 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white">New Transaction</h2>
            <button
              @click="handleTransactionFormClose"
              class="text-gray-400 hover:text-white"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="p-4">
            <TransactionForm
              v-if="showTransactionForm"
              :categories="categories"
              :family-users="familyUsers"
              @created="handleTransactionCreated"
              @close="handleTransactionFormClose"
            />
          </div>
        </div>
      </div>
    </Transition>

    <div class="h-20" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuth } from '../composables/useAuth';
import { useApi } from '../composables/useApi';
import TransactionForm from './TransactionForm.vue';

const router = useRouter();
const route = useRoute();
const { user, logout } = useAuth();
const { get } = useApi();
const showAdminMenu = ref(false);
const showTransactionForm = ref(false);
const categories = ref([]);
const familyUsers = ref([]);

const isAdminPage = computed(() => {
  return route.path.startsWith('/admin');
});

function isActive(path) {
  return route.path.startsWith(path);
}

async function handleLogout() {
  try {
    await logout();
    await router.push('/login');
  } catch (err) {
    console.error('Logout failed:', err);
  }
}

function handleTransactionFormClose() {
  showTransactionForm.value = false;
}

async function handleTransactionCreated(transaction) {
  handleTransactionFormClose();
  window.dispatchEvent(new CustomEvent('transaction-created', { detail: transaction }));
}

onMounted(async () => {
  try {
    const [catData, usersData] = await Promise.all([
      get('/categories'),
      get('/family/users'),
    ]);
    categories.value = catData;
    familyUsers.value = usersData;
  } catch (err) {
    console.error('Failed to fetch data:', err);
  }
});
</script>
