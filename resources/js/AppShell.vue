<template>
  <div class="min-h-dvh min-h-screen w-full max-w-full overflow-x-clip">
    <AppNav v-if="user">
      <router-view />
    </AppNav>
    <router-view v-else />
  </div>
</template>

<script setup>
import AppNav from './components/AppNav.vue';
import { useAuth } from './composables/useAuth';

const { user } = useAuth();
</script>
