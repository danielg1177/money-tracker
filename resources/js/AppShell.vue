<template>
  <AppNav v-if="user">
    <router-view />
  </AppNav>
  <router-view v-else />
</template>

<script setup>
import AppNav from './components/AppNav.vue';
import { useAuth } from './composables/useAuth';

const { user } = useAuth();
</script>
