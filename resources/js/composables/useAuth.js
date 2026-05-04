import { ref, onMounted } from 'vue';
import { normalizeAuthUser } from '../support/authUser';

export function useAuth() {
  const user = ref(null);

  onMounted(() => {
    const userJson = localStorage.getItem('user');
    if (userJson) {
      user.value = normalizeAuthUser(JSON.parse(userJson));
    }
  });

  async function login(email, password) {
    await window.axios.post('/login', { email, password });
    await fetchUser();
  }

  async function logout() {
    try {
      await window.axios.post('/logout');
    } finally {
      localStorage.removeItem('user');
      user.value = null;
    }
  }

  async function fetchUser() {
    try {
      const response = await window.axios.get('/user');
      user.value = normalizeAuthUser(response.data);
      localStorage.setItem('user', JSON.stringify(user.value));
    } catch (error) {
      localStorage.removeItem('user');
      user.value = null;
      throw error;
    }
  }

  return { user, login, logout, fetchUser };
}
