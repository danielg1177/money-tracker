import './bootstrap';

import { createApp } from 'vue';
import router from './router/index.js';
import AppShell from './AppShell.vue';

let isHandlingSessionTimeout = false;

if ('scrollRestoration' in window.history) {
  window.history.scrollRestoration = 'manual';
}

window.axios.interceptors.response.use(
  (response) => {
    return response;
  },
  (error) => {
    const status = error?.response?.status;
    const requestUrl = error?.config?.url ?? '';
    const isAuthEndpoint = requestUrl.includes('/login') || requestUrl.includes('/logout');
    const isSessionExpired = status === 401 || status === 419;

    if (isSessionExpired && !isAuthEndpoint && !isHandlingSessionTimeout) {
      isHandlingSessionTimeout = true;
      localStorage.removeItem('user');
      window.location.assign('/login');
    }

    return Promise.reject(error);
  },
);

createApp(AppShell)
  .use(router)
  .mount('#app');
