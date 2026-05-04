<template>
  <div class="flex items-center justify-center min-h-screen bg-gray-950 px-4">
    <div class="w-full max-w-sm">
      <div class="bg-gray-900 rounded-2xl shadow-2xl p-6 border border-gray-800">
        <h1 class="text-xl font-bold text-white mb-2">Sign In</h1>
        <p class="text-gray-400 text-xs mb-6">Enter your credentials to continue</p>

        <form @submit.prevent="handleLogin" class="space-y-3">
          <div>
            <label for="email" class="block text-xs font-medium text-gray-300 mb-2">
              Email
            </label>
            <input
              id="email"
              v-model="email"
              type="email"
              required
              :disabled="loading"
              class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors disabled:opacity-50"
              placeholder="you@example.com"
            />
          </div>

          <div>
            <label for="password" class="block text-xs font-medium text-gray-300 mb-2">
              Password
            </label>
            <input
              id="password"
              v-model="password"
              type="password"
              required
              :disabled="loading"
              class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors disabled:opacity-50"
              placeholder="••••••••"
            />
          </div>

          <div v-if="error" class="mt-3 p-3 bg-red-900/20 border border-red-700/50 rounded-lg">
            <p class="text-xs text-red-400">{{ error }}</p>
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="w-full mt-4 py-2 px-3 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-700 text-white font-medium text-sm rounded-lg transition-colors duration-200 disabled:cursor-not-allowed"
          >
            <span v-if="loading" class="flex items-center justify-center gap-2">
              <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
              Signing in...
            </span>
            <span v-else>Sign In</span>
          </button>
        </form>
      </div>

      <p class="text-center text-gray-400 text-xs mt-3">
        Contact an administrator to create your account
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../composables/useAuth';

const router = useRouter();
const { login } = useAuth();

const email = ref('');
const password = ref('');
const error = ref(null);
const loading = ref(false);

async function handleLogin() {
  error.value = null;
  loading.value = true;

  try {
    await login(email.value, password.value);
    await router.push('/dashboard');
  } catch (err) {
    error.value = err.response?.data?.message || 'Invalid credentials. Please try again.';
  } finally {
    loading.value = false;
  }
}
</script>
