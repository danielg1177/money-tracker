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
            <h2 class="text-xl font-semibold text-slate-900">Welcome back</h2>
            <p class="mt-2 text-sm text-slate-600">You're signed in and ready to start tracking income, expenses, split debt, and fund allocations.</p>
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

const authenticated = ref(false);
const error = ref('');

const form = reactive({
  email: '',
  password: '',
});

const submitLogin = async () => {
  error.value = '';

  try {
    await window.axios.post('/login', {
      email: form.email,
      password: form.password,
    });

    authenticated.value = true;
  } catch (caught) {
    error.value = caught.response?.data?.message || 'Unable to sign in. Please check your credentials.';
  }
};

const logout = async () => {
  await window.axios.post('/logout');
  authenticated.value = false;
  form.email = '';
  form.password = '';
};
</script>
