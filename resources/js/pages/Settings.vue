<template>
  <div class="pb-32">
    <div class="sticky top-0 pt-safe bg-gray-900 border-b border-gray-800 px-4 py-3 z-10">
      <h1 class="text-xl font-bold text-white">Settings</h1>
      <p class="text-gray-400 text-sm mt-1">Your account and family view</p>
    </div>

    <div class="px-4 mt-4 space-y-4">
      <section class="rounded-xl border border-gray-700 bg-gray-800 p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Account</h2>
        <p class="mt-3 text-xs text-gray-500">Email</p>
        <p class="mt-1 text-sm text-gray-100 break-all">{{ user?.email || '—' }}</p>
      </section>

      <section class="rounded-xl border border-gray-700 bg-gray-800 p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Change password</h2>
        <form class="mt-3 space-y-3" @submit.prevent="handlePasswordSubmit">
          <div>
            <label for="current-password" class="block text-xs font-medium text-gray-300 mb-1">Current password</label>
            <input
              id="current-password"
              v-model="currentPassword"
              type="password"
              autocomplete="current-password"
              required
              :disabled="passwordSaving"
              class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white text-sm placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:opacity-50"
            />
            <p v-if="passwordFieldErrors.current_password" class="mt-1 text-xs text-red-400">{{ passwordFieldErrors.current_password }}</p>
          </div>
          <div>
            <label for="new-password" class="block text-xs font-medium text-gray-300 mb-1">New password</label>
            <input
              id="new-password"
              v-model="newPassword"
              type="password"
              autocomplete="new-password"
              required
              :disabled="passwordSaving"
              class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white text-sm placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:opacity-50"
            />
            <p v-if="passwordFieldErrors.password" class="mt-1 text-xs text-red-400">{{ passwordFieldErrors.password }}</p>
          </div>
          <div>
            <label for="confirm-password" class="block text-xs font-medium text-gray-300 mb-1">Confirm new password</label>
            <input
              id="confirm-password"
              v-model="newPasswordConfirmation"
              type="password"
              autocomplete="new-password"
              required
              :disabled="passwordSaving"
              class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white text-sm placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:opacity-50"
            />
            <p v-if="passwordFieldErrors.password_confirmation" class="mt-1 text-xs text-red-400">{{ passwordFieldErrors.password_confirmation }}</p>
          </div>
          <p v-if="passwordError" class="text-xs text-red-400">{{ passwordError }}</p>
          <p v-if="passwordSuccess" class="text-xs text-emerald-400">{{ passwordSuccess }}</p>
          <button
            type="submit"
            :disabled="passwordSaving"
            class="w-full min-h-11 py-2 px-3 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-700 text-white font-medium text-sm rounded-lg transition-colors disabled:cursor-not-allowed"
          >
            {{ passwordSaving ? 'Updating…' : 'Update password' }}
          </button>
        </form>
      </section>

      <section v-if="user?.family_id" class="rounded-xl border border-gray-700 bg-gray-800 p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Family expenses</h2>
        <p class="mt-2 text-xs text-gray-500 leading-snug">
          When this is on, Transactions and View month show everyone’s household activity. You can still edit and delete your own transactions; other members’ rows stay browse-only. Your own totals stay visible next to the family combined figures. Split expenses you already see are not listed twice.
        </p>
        <label class="mt-4 flex items-center justify-between gap-3 min-h-11">
          <span class="text-sm text-gray-200">View all family expenses</span>
          <button
            type="button"
            role="switch"
            :aria-checked="viewFamilyExpenses"
            :disabled="toggleSaving"
            class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            :class="viewFamilyExpenses ? 'bg-blue-600' : 'bg-gray-600'"
            @click="toggleFamilyExpenseView"
          >
            <span
              class="inline-block h-5 w-5 rounded-full bg-white transition-transform"
              :class="viewFamilyExpenses ? 'translate-x-6' : 'translate-x-1'"
            />
          </button>
        </label>
        <p v-if="toggleError" class="mt-2 text-xs text-red-400">{{ toggleError }}</p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useAuth } from '../composables/useAuth';
import { useApi } from '../composables/useApi';

const { user, fetchUser } = useAuth();
const { put } = useApi();

const currentPassword = ref('');
const newPassword = ref('');
const newPasswordConfirmation = ref('');
const passwordSaving = ref(false);
const passwordError = ref('');
const passwordSuccess = ref('');
const passwordFieldErrors = ref({});

const toggleSaving = ref(false);
const toggleError = ref('');

const viewFamilyExpenses = computed(() => Boolean(user.value?.view_family_expenses));

function firstError(errors, key) {
  const value = errors?.[key];
  if (Array.isArray(value) && value.length > 0) {
    return String(value[0]);
  }
  if (typeof value === 'string' && value) {
    return value;
  }
  return '';
}

async function handlePasswordSubmit() {
  passwordError.value = '';
  passwordSuccess.value = '';
  passwordFieldErrors.value = {};
  passwordSaving.value = true;

  try {
    await window.axios.put('/user/password', {
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: newPasswordConfirmation.value,
    });
    currentPassword.value = '';
    newPassword.value = '';
    newPasswordConfirmation.value = '';
    passwordSuccess.value = 'Password updated.';
  } catch (err) {
    const errors = err.response?.data?.errors || {};
    passwordFieldErrors.value = {
      current_password: firstError(errors, 'current_password'),
      password: firstError(errors, 'password'),
      password_confirmation: firstError(errors, 'password_confirmation'),
    };
    passwordError.value = err.response?.data?.message
      || (Object.values(passwordFieldErrors.value).some(Boolean) ? '' : 'Could not update password.');
  } finally {
    passwordSaving.value = false;
  }
}

async function toggleFamilyExpenseView() {
  if (toggleSaving.value || !user.value?.family_id) {
    return;
  }

  toggleError.value = '';
  toggleSaving.value = true;
  const nextValue = !viewFamilyExpenses.value;

  try {
    await put('/user/settings', { view_family_expenses: nextValue });
    await fetchUser();
  } catch (err) {
    toggleError.value = err.response?.data?.message || 'Could not update family expense view.';
  } finally {
    toggleSaving.value = false;
  }
}
</script>
