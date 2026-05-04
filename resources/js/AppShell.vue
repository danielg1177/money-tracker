<template>
  <AppNav v-if="user">
    <router-view />
  </AppNav>
  <router-view v-else />
</template>

<script setup>
import { ref, onMounted } from 'vue';
import AppNav from './components/AppNav.vue';
import { normalizeAuthUser } from './support/authUser';

const user = ref(null);

onMounted(() => {
  const userJson = localStorage.getItem('user');
  user.value = userJson ? normalizeAuthUser(JSON.parse(userJson)) : null;
});
</script>
